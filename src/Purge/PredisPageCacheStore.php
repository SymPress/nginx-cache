<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use Predis\Client;
use SymPress\NginxCache\Settings\CompatibilitySettings;

final class PredisPageCacheStore implements RedisPageCacheStore
{
    private ?Client $client = null;

    public function __construct(private readonly CompatibilitySettings $settings)
    {
    }

    public function scan(string $cursor, string $prefix): array
    {
        $result = $this->client()->executeRaw(['SCAN', $cursor, 'MATCH', $prefix . '*', 'COUNT', '200']);
        if (!is_array($result) || count($result) !== 2 || !is_array($result[1])) {
            throw new \RuntimeException('Invalid Redis scan response.');
        }
        return [(string) $result[0], array_values(array_filter($result[1], is_string(...)))];
    }

    public function delete(array $keys): int
    {
        return $keys === [] ? 0 : (int) $this->client()->executeRaw(['DEL', ...$keys]);
    }

    private function client(): Client
    {
        return $this->client ??= new Client($this->settings->redisConnection());
    }
}
