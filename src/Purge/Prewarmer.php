<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PrewarmResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class Prewarmer
{
    public const int BATCH_SIZE = 5;

    public function __construct(
        private HttpClientInterface $http,
        private WordPressCacheSettings $settings,
        private UrlPolicy $urls,
        private CacheClock $clock,
        private ?SitemapUrlProvider $sitemaps = null,
    ) {
    }

    /** @param list<string> $urls */
    public function prewarm(array $urls = []): PrewarmResult
    {
        $plan = $this->plan($urls);
        $result = $this->warmUrls($plan->urls);
        return new PrewarmResult($result->urls, $result->responses, [...$plan->errors, ...$result->errors]);
    }

    /** @param list<string> $urls */
    public function plan(array $urls = []): PrewarmResult
    {
        $discover = $urls === [] && ($this->sitemaps?->enabled() ?? false);
        $urls = $urls !== [] ? $urls : $this->settings->prewarmUrls();
        $errors = [];
        if ($discover && $this->sitemaps !== null) {
            $discovered = $this->sitemaps->discover(max(0, $this->settings->maxPrewarmUrls() - count($urls)));
            $urls = [...$urls, ...$discovered['urls']];
            $errors = $discovered['errors'];
        }
        $urls = array_values(
            array_slice(
                array_unique(
                    array_filter(
                        array_map($this->urls->normalizeSameOriginHttpUrl(...), $urls),
                        static fn (string $url): bool => $url !== '',
                    ),
                ),
                0,
                $this->settings->maxPrewarmUrls(),
            ),
        );
        return new PrewarmResult($urls, [], $errors);
    }

    /** @param list<string> $urls */
    public function warmUrls(array $urls): PrewarmResult
    {
        $normalized = array_map($this->urls->normalizeSameOriginHttpUrl(...), $urls);
        $errors = in_array('', $normalized, true) ? ['A prewarm target no longer uses this site origin.'] : [];
        $urls = array_values(array_unique(array_filter($normalized, static fn (string $url): bool => $url !== '')));
        $responses = [];

        foreach ($urls as $url) {
            try {
                $response = $this->http->request('GET', $url, [
                    'headers'       => [
                        'User-Agent' => 'SymPress Nginx Cache Prewarmer',
                    ],
                    'max_redirects' => 0,
                    'timeout'       => 5,
                    'max_duration'  => 5,
                ]);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    $errors[] = sprintf('%s: HTTP %d.', $url, $status);
                } else {
                    $responses[$url] = $status;
                }
            } catch (\Throwable) {
                $errors[] = sprintf('%s: Prewarm request failed.', $url);
            }

            $delay = $this->settings->prewarmDelayMilliseconds();

            if ($delay <= 0) {
                continue;
            }

            $this->clock->sleepMicroseconds($delay * 1000);
        }

        return new PrewarmResult($urls, $responses, $errors);
    }
}
