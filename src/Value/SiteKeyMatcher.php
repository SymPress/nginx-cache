<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Value;

use Symfony\Component\Filesystem\Path;

final readonly class SiteKeyMatcher
{
    /** @var array<string, array<string, true>> */
    private array $boundaries;

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
        $boundaries = [];
        foreach ($roots as $host => $own) {
            foreach ([$own, ...($paths[$host] ?? [])] as $prefix) {
                $boundaries[$host][self::prefix($prefix)] = true;
            }
        }
        $this->boundaries = $boundaries;
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
        if ($path !== rtrim($own, '/') && !str_starts_with($path, $own)) {
            return false;
        }
        // Check URI ancestors rather than every site in the network. The
        // longest registered ancestor owns the key, including nested sites.
        $winner = isset($this->boundaries[$host]['/']) ? '/' : '';
        $prefix = '/';
        foreach (explode('/', trim($path, '/')) as $segment) {
            $prefix .= $segment . '/';
            if (!isset($this->boundaries[$host][$prefix])) {
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
