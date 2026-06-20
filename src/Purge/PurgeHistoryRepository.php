<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeResult;

final readonly class PurgeHistoryRepository
{
    private const string OPTION_LAST = 'sympress_nginx_cache_last_purge';
    private const string OPTION_HISTORY = 'sympress_nginx_cache_purge_history';
    private const int MAX_HISTORY = 20;

    public function __construct(
        private CacheClock $clock,
    ) {
    }

    public function record(PurgeResult $result): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        $record = [
            ...$result->toArray(),
            'created_at' => $result->createdAt > 0 ? $result->createdAt : $this->clock->timestamp(),
            'actor'      => $this->actor(),
            'client_ip'  => $this->clientIp(),
        ];
        update_option(self::OPTION_LAST, $record, false);

        $history = $this->history();
        array_unshift($history, $record);
        update_option(self::OPTION_HISTORY, array_slice($history, 0, self::MAX_HISTORY), false);
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        if (!function_exists('get_option')) {
            return null;
        }

        $record = get_option(self::OPTION_LAST, null);

        return is_array($record) ? $record : null;
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }

        $history = get_option(self::OPTION_HISTORY, []);

        if (!is_array($history)) {
            return [];
        }

        return array_values(
            array_filter($history, static fn (mixed $record): bool => is_array($record)),
        );
    }

    /** @return array{id: int|null, login: string|null} */
    private function actor(): array
    {
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $login = null;

        if ($userId > 0 && function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();

            if (is_object($user) && property_exists($user, 'user_login') && is_string($user->user_login)) {
                $login = $user->user_login;
            }
        }

        return [
            'id'    => $userId > 0 ? $userId : null,
            'login' => $login,
        ];
    }

    private function clientIp(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = is_string($_SERVER[$key] ?? null) ? (string) $_SERVER[$key] : '';

            if ($value === '') {
                continue;
            }

            $candidate = trim(explode(',', $value)[0]);

            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return null;
    }
}
