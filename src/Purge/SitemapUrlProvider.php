<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use DOMDocument;
use DOMElement;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SitemapUrlProvider
{
    public function __construct(
        private HttpClientInterface $http,
        private CompatibilitySettings $settings,
        private UrlPolicy $policy,
    ) {
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
                    throw new \RuntimeException('External XML declarations are forbidden.');
                }
                $document = new DOMDocument();
                if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                    throw new \RuntimeException('Invalid sitemap XML.');
                }
                $rootElement = $document->documentElement;
                if (!$rootElement instanceof DOMElement || !in_array($rootElement->localName, ['sitemapindex', 'urlset'], true)) {
                    throw new \RuntimeException('Invalid sitemap document.');
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
            } catch (\Throwable) {
                $errors[] = 'Sitemap discovery failed; check its URL, XML and server response.';
            }
        }
        return ['urls' => array_keys($pages), 'errors' => array_values(array_unique($errors))];
    }

    private function read(string $url): string
    {
        $response = $this->http->request('GET', $url, ['max_redirects' => 0, 'timeout' => 5, 'max_duration' => 5, 'buffer' => false]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Sitemap request failed.');
        }
        $xml = '';
        foreach ($this->http->stream($response, 5) as $chunk) {
            if ($chunk->isTimeout()) {
                $response->cancel();
                throw new \RuntimeException('Sitemap request timed out.');
            }
            $xml .= $chunk->getContent();
            if (strlen($xml) > 2_097_152) {
                $response->cancel();
                throw new \RuntimeException('Sitemap exceeds the byte budget.');
            }
        }
        return $xml;
    }
}
