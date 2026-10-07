<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

interface RedisPageCacheStore
{
    /** @return array{0: string, 1: list<string>} */
    public function scan(string $cursor, string $prefix): array;

    /** @param list<string> $keys */
    public function delete(array $keys): int;
}
