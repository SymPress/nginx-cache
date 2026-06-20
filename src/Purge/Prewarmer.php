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
    public function __construct(
        private HttpClientInterface $http,
        private WordPressCacheSettings $settings,
        private UrlPolicy $urls,
        private CacheClock $clock,
    ) {
    }

    /** @param list<string> $urls */
    public function prewarm(array $urls = []): PrewarmResult
    {
        $urls = $urls !== [] ? $urls : $this->settings->prewarmUrls();
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
        $responses = [];
        $errors = [];

        foreach ($urls as $url) {
            try {
                $response = $this->http->request('GET', $url, [
                    'headers'       => [
                        'User-Agent' => 'SymPress Nginx Cache Prewarmer',
                    ],
                    'max_redirects' => 3,
                    'timeout'       => 5,
                ]);
                $responses[$url] = $response->getStatusCode();
            } catch (\Throwable $exception) {
                $errors[] = sprintf('%s: %s', $url, $exception->getMessage());
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
