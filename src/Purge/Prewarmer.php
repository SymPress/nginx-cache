<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\CompatibilitySettings;
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

    /**
     * @param list<string> $urls
     * @param list<string> $affectedUrls
     */
    public function plan(array $urls = [], bool $withRelated = false, array $affectedUrls = []): PrewarmResult
    {
        if ($withRelated && $urls === [] && $affectedUrls !== []) {
            $urls = $affectedUrls;
        }
        $affectedUrls = $affectedUrls !== [] ? $affectedUrls : array_slice($urls, 0, 1);
        $home = function_exists('home_url') ? home_url('/') : '';
        $affectedOnly = (new CompatibilitySettings($this->settings))->integer('prewarm_affected_only') !== 0;
        if ($withRelated && $affectedOnly) {
            $urls = array_values(array_intersect($urls, $affectedUrls));
            if ($urls === []) {
                return new PrewarmResult([], [], []);
            }
        }
        $discover = $urls === [] && ($this->sitemaps?->enabled() ?? false) && !($withRelated && $affectedOnly);
        if ($withRelated && $urls !== [] && !$affectedOnly && $home !== '') {
            $urls[] = $home;
        }
        $urls = $urls !== [] ? $urls : $this->settings->prewarmUrls();
        $errors = [];
        $priorities = [];
        foreach ($urls as $url) {
            $priorities[$url] = in_array($url, $affectedUrls, true) ? 0 : ($url === $home ? 1 : 2);
        }
        if ($discover && $this->sitemaps !== null) {
            $discovered = $this->sitemaps->discover(max(0, $this->settings->maxPrewarmUrls() - count($urls)));
            foreach ($discovered['urls'] as $url) {
                $priorities[$url] ??= 3;
            }
            $urls = [...$urls, ...$discovered['urls']];
            $errors = $discovered['errors'];
        }
        $normalizedPriorities = [];
        foreach ($priorities as $url => $priority) {
            $normalized = $this->urls->normalizeSameOriginHttpUrl($url);
            if ($normalized === '') {
                continue;
            }
            $normalizedPriorities[$normalized] = min($priority, $normalizedPriorities[$normalized] ?? $priority);
        }
        $priorities = $normalizedPriorities;
        $urls = array_values(array_unique(array_filter(array_map($this->urls->normalizeSameOriginHttpUrl(...), $urls), static fn (string $url): bool => $url !== '')));
        if ($withRelated) {
            usort($urls, static fn (string $left, string $right): int => ($priorities[$left] ?? 2) <=> ($priorities[$right] ?? 2));
        }
        $urls = array_slice($urls, 0, $this->settings->maxPrewarmUrls());
        return new PrewarmResult($urls, [], $errors, array_intersect_key($priorities, array_flip($urls)));
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
