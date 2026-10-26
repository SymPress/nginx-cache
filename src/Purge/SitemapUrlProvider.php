<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use DOMDocument;
use DOMElement;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Support\SitemapDiscoveryFailure;
use SymPress\NginxCache\Time\CacheClock;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SitemapUrlProvider
{
    public const string FAILURE_OPTION = 'sympress_nginx_cache_sitemap_discovery_failure';
    private const int MAX_REPORTED_ERRORS = 5;

    public function __construct(
        private HttpClientInterface $http,
        private CompatibilitySettings $settings,
        private UrlPolicy $policy,
        private CacheClock $clock,
    ) {
    }

    /** @return array{failed_at: int, errors: list<string>}|null */
    public static function lastFailure(): ?array
    {
        $failure = function_exists('get_option') ? get_option(self::FAILURE_OPTION, null) : null;
        if (!is_array($failure) || !is_int($failure['failed_at'] ?? null) || !is_array($failure['errors'] ?? null)) {
            return null;
        }
        return ['failed_at' => $failure['failed_at'], 'errors' => array_values(array_filter($failure['errors'], is_string(...)))];
    }

    public function enabled(): bool
    {
        return $this->settings->integer('prewarm_sitemap') !== 0;
    }

    /** @return array{urls: list<string>, errors: list<string>} */
    public function discover(int $limit): array
    {
        if ($limit <= 0) {
            return ['urls' => [], 'errors' => []];
        }
        $root = $this->settings->string('prewarm_sitemap_url');
        $root = $root !== '' ? $root : (function_exists('home_url') ? home_url('/wp-sitemap.xml') : '');
        $pending = [$root];
        $seen = [];
        $pages = [];
        $errors = [];
        while ($pending !== [] && count($seen) < 20 && count($pages) < $limit) {
            $url = $this->policy->normalizeSameOriginHttpUrl((string) array_shift($pending));
            if ($url === '') {
                $errors[] = 'Sitemap URL must use the configured site origin.';
                continue;
            }
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            try {
                $xml = $this->read($url);
                if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
                    throw new SitemapDiscoveryFailure('Sitemap XML declarations are forbidden.');
                }
                $document = new DOMDocument();
                if ($xml === '' || !$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                    throw new SitemapDiscoveryFailure('Sitemap XML is invalid.');
                }
                $rootElement = $document->documentElement;
                if (!$rootElement instanceof DOMElement || !in_array($rootElement->localName, ['sitemapindex', 'urlset'], true)) {
                    throw new SitemapDiscoveryFailure('Sitemap XML is not a sitemap or sitemap index.');
                }
                foreach ($rootElement->childNodes as $entry) {
                    if (!$entry instanceof DOMElement || !in_array($entry->localName, ['url', 'sitemap'], true)) {
                        continue;
                    }
                    foreach ($entry->childNodes as $location) {
                        if (!$location instanceof DOMElement || $location->localName !== 'loc') {
                            continue;
                        }
                        $target = $this->policy->normalizeSameOriginHttpUrl(trim($location->textContent));
                        if ($target === '') {
                            continue;
                        }
                        if ($rootElement->localName === 'sitemapindex') {
                            if (count($pending) < 100 && !isset($seen[$target])) {
                                $pending[] = $target;
                            }
                        } else {
                            $pages[$target] = true;
                            if (count($pages) >= $limit) {
                                break 2;
                            }
                        }
                    }
                }
            } catch (SitemapDiscoveryFailure $failure) {
                $errors[] = $url . ': ' . $failure->getMessage();
            } catch (\Throwable) {
                $errors[] = $url . ': Sitemap discovery failed.';
            }
        }
        $errors = array_values(array_unique($errors));
        $this->report($errors);
        return ['urls' => array_keys($pages), 'errors' => $errors];
    }

    private function read(string $url): string
    {
        $started = $this->clock->highResolutionTimestamp();
        try {
            $response = $this->http->request('GET', $url, ['max_redirects' => 0, 'timeout' => 5, 'max_duration' => 5, 'buffer' => false]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new SitemapDiscoveryFailure(sprintf('Sitemap HTTP %d.', $status));
            }
            $xml = '';
            foreach ($this->http->stream($response, 5) as $chunk) {
                if ($chunk->isTimeout()) {
                    $response->cancel();
                    throw new SitemapDiscoveryFailure('Sitemap request timed out.');
                }
                $xml .= $chunk->getContent();
                if (strlen($xml) > 2_097_152) {
                    $response->cancel();
                    throw new SitemapDiscoveryFailure('Sitemap exceeds the 2 MiB byte budget.');
                }
            }
            return $xml;
        } catch (TransportExceptionInterface) {
            // Provider messages may echo response details; max_duration surfaces as a plain transport error.
            throw new SitemapDiscoveryFailure($this->clock->elapsedSince($started) >= 5.0 ? 'Sitemap request timed out.' : 'Sitemap request failed.');
        }
    }

    /** @param list<string> $errors */
    private function report(array $errors): void
    {
        if (!function_exists('update_option')) {
            return;
        }
        try {
            if ($errors === []) {
                delete_option(self::FAILURE_OPTION);
                return;
            }
            update_option(self::FAILURE_OPTION, ['failed_at' => $this->clock->timestamp(), 'errors' => array_slice($errors, 0, self::MAX_REPORTED_ERRORS)], false);
        } catch (\Throwable) {
            // Diagnostics never change the discovery result.
        }
    }
}
