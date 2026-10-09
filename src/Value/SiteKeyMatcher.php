<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Value;

use Symfony\Component\Filesystem\Path;

final readonly class SiteKeyMatcher
{
    /** @return array{roots: array<string, string>, paths: array<string, list<string>>} */
    public function toArray(): array
    {
        return ['roots' => $this->roots, 'paths' => $this->paths];
    }

    /**
     * @param array<string, string> $roots Owned host => site path.
     * @param array<string, list<string>> $paths All network site paths on each host.
     */
    public function __construct(public array $roots, private array $paths = [])
    {
    }

    public function matches(string $host, string $uri): bool
    {
        $host = strtolower($host);
        if (!isset($this->roots[$host]) || !str_starts_with($uri, '/')) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This pure value also runs outside WordPress; only an absolute URI path is accepted.
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }
        $path = '/' . ltrim(Path::canonicalize(rawurldecode($path)), '/');
        $own = self::prefix($this->roots[$host]);
        $winner = '';
        foreach (array_unique([$own, ...($this->paths[$host] ?? [])]) as $prefix) {
            $prefix = self::prefix($prefix);
            if (($path !== rtrim($prefix, '/') && !str_starts_with($path, $prefix)) || strlen($prefix) <= strlen($winner)) {
                continue;
            }

            $winner = $prefix;
        }
        return $winner === $own;
    }

    public static function prefix(string $path): string
    {
        return '/' . trim($path, '/') . (trim($path, '/') === '' ? '' : '/');
    }
}
