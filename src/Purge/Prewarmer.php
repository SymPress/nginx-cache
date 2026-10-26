<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PrewarmResult;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class Prewarmer
{
    public const int BATCH_SIZE = 5;
    public const int MAX_REDIRECTS = 3;

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
        return new PrewarmResult($result->urls, $result->responses, [...$plan->errors, ...$result->errors], issues: $result->issues);
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
        $errors = [];
        $issues = [];
        $targets = [];
        foreach ($urls as $url) {
            $normalized = $this->urls->normalizeSameOriginHttpUrl($url);
            if ($normalized === '') {
                // Plan entries are already validated URLs; an origin change makes them permanently stale.
                $issues[$url] = ['reason' => 'Target no longer uses this site origin.', 'stale' => true];
                continue;
            }
            $targets[$normalized] = true;
        }
        $urls = array_keys($targets);
        $responses = [];

        foreach ($urls as $url) {
            $target = $url;
            $visited = [];
            try {
                while (true) {
                    $visited[$target] = true;
                    try {
                        $response = $this->http->request('GET', $target, [
                            'headers'       => [
                                'User-Agent' => 'SymPress Nginx Cache Prewarmer',
                            ],
                            'max_redirects' => 0,
                            'timeout'       => 5,
                            'max_duration'  => 5,
                            'buffer'        => false,
                        ]);
                        $status = $response->getStatusCode();
                        $location = $status >= 300 && $status < 400 ? (string) ($response->getHeaders(false)['location'][0] ?? '') : '';
                        $transferred = $status < 200 || $status >= 300 || $this->transferred($response);
                    } finally {
                        $this->delay();
                    }
                    if ($location === '') {
                        break;
                    }
                    // Each hop is re-validated; external, looping or long chains are stale targets.
                    $next = $this->urls->normalizeSameOriginHttpUrl($this->absoluteUrl($target, $location));
                    if ($next === '' || isset($visited[$next]) || count($visited) > self::MAX_REDIRECTS) {
                        $issues[$url] = ['reason' => sprintf('HTTP %d: redirect leaves the site, loops or exceeds %d hops.', $status, self::MAX_REDIRECTS), 'stale' => true];
                        continue 2;
                    }
                    $target = $next;
                }
            } catch (\Throwable) {
                $errors[] = sprintf('%s: Prewarm request failed.', $url);
                $issues[$url] = ['reason' => 'Prewarm request failed.', 'stale' => false];
                continue;
            }
            if (!$transferred) {
                $errors[] = sprintf('%s: HTTP %d response transfer was incomplete.', $url, $status);
                $issues[$url] = ['reason' => sprintf('HTTP %d response transfer was incomplete.', $status), 'stale' => false];
            } elseif ($status >= 200 && $status < 300) {
                $responses[$url] = $status;
            } elseif ($status >= 300 && $status < 500 && !in_array($status, [408, 425, 429], true)) {
                $issues[$url] = ['reason' => sprintf('HTTP %d: target is outdated, removed or rejected.', $status), 'stale' => true];
            } else {
                $errors[] = sprintf('%s: HTTP %d.', $url, $status);
                $issues[$url] = ['reason' => sprintf('HTTP %d.', $status), 'stale' => false];
            }
        }

        return new PrewarmResult($urls, $responses, $errors, issues: $issues);
    }

    /** Nginx stores an entry only after the complete body arrived; chunks are discarded, never buffered. */
    private function transferred(ResponseInterface $response): bool
    {
        try {
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    $response->cancel();
                    return false;
                }
                $chunk->getContent();
            }
        } catch (TransportExceptionInterface) {
            return false;
        }
        return true;
    }

    private function delay(): void
    {
        $delay = $this->settings->prewarmDelayMilliseconds();
        if ($delay <= 0) {
            return;
        }
        $this->clock->sleepMicroseconds($delay * 1000);
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Parse the already validated URL without requiring WordPress in unit tests.
        $parts = parse_url($base) ?: [];
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = $parts['path'] ?? '/';
        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }
}
