<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Settings;

use SymPress\NginxCache\Security\SecretCipher;

final readonly class CompatibilitySettings
{
    public const string PREFIX = 'sympress_nginx_cache_';
    public const string REDIS_PASSWORD = self::PREFIX . 'redis_password';

    public function __construct(
        private WordPressCacheSettings $settings,
        private SecretCipher $secrets = new SecretCipher(),
        private OptionSource $options = new OptionSource(),
    ) {
    }

    public function register(): void
    {
        foreach ($this->defaults() as $name => $default) {
            register_setting('sympress_nginx_cache', self::PREFIX . $name, [
                'type'              => is_int($default) ? 'integer' : 'string',
                'default'           => $default,
                'sanitize_callback' => fn (mixed $value): mixed => $this->sanitize($name, $value),
            ]);
        }
        register_setting('sympress_nginx_cache', self::REDIS_PASSWORD, [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => fn (mixed $value): string => $this->settings->sanitizeStoredSecret($value, self::REDIS_PASSWORD),
        ]);
    }

    /** @return array<string, int|string> */
    public static function defaults(): array
    {
        return [
            'purge_backend'                => 'local_files',
            'redis_host'                   => '127.0.0.1',
            'redis_port'                   => 6379,
            'redis_database'               => 0,
            'redis_prefix'                 => 'nginx-cache:',
            'redis_socket'                 => '',
            'redis_username'               => '',
            'http_purge_prefix'            => '/purge',
            'prewarm_sitemap'              => 0,
            'prewarm_sitemap_url'          => '',
            'html_stamp'                   => 0,
            'purge_home_edit'              => 1,
            'purge_home_delete'            => 1,
            'purge_home_comment_new'       => 1,
            'purge_home_comment_delete'    => 1,
            'purge_page_edit'              => 1,
            'purge_page_delete'            => 1,
            'purge_page_comment_new'       => 1,
            'purge_page_comment_delete'    => 1,
            'purge_archive_edit'           => 1,
            'purge_archive_delete'         => 1,
            'purge_archive_comment_new'    => 1,
            'purge_archive_comment_delete' => 1,
        ];
    }

    public function sanitize(string $name, mixed $value): int|string
    {
        if ($name === 'purge_backend') {
            return in_array($value, ['local_files', 'redis', 'http'], true) ? $value : 'local_files';
        }
        if (is_int($this->defaults()[$name] ?? null)) {
            return max(0, (int) $value);
        }
        return is_string($value) ? trim(sanitize_text_field($value)) : '';
    }

    public function string(string $name): string
    {
        $constant = 'SYMPRESS_NGINX_CACHE_' . strtoupper($name);
        $value = defined($constant) ? constant($constant) : $this->value($name);
        return is_string($value) ? trim($value) : '';
    }

    public function integer(string $name): int
    {
        $constant = 'SYMPRESS_NGINX_CACHE_' . strtoupper($name);
        return (int) (defined($constant) ? constant($constant) : $this->value($name));
    }

    private function value(string $name): mixed
    {
        $default = $this->defaults()[$name] ?? '';
        return $this->options->value(self::PREFIX . $name, $default);
    }

    /** @return array<string, int|string|float> */
    public function redisConnection(): array
    {
        $stored = $this->options->value(self::REDIS_PASSWORD, '');
        $password = defined('SYMPRESS_NGINX_CACHE_REDIS_PASSWORD')
            ? (string) constant('SYMPRESS_NGINX_CACHE_REDIS_PASSWORD')
            : $this->secrets->decrypt(is_string($stored) ? $stored : null, self::REDIS_PASSWORD);
        if ($stored !== '' && $password === null) {
            throw new \RuntimeException('Redis credentials are unavailable.');
        }
        $socket = $this->string('redis_socket');
        $parameters = [
            'scheme'             => $socket === '' ? 'tcp' : 'unix',
            'host'               => $this->string('redis_host'),
            'port'               => max(1, min(65535, $this->integer('redis_port'))),
            'path'               => $socket,
            'database'           => max(0, $this->integer('redis_database')),
            'username'           => $this->string('redis_username'),
            'password'           => $password ?? '',
            'timeout'            => 3.0,
            'read_write_timeout' => 3.0,
        ];
        foreach (['username', 'password'] as $name) {
            if (($parameters[$name] ?? null) !== '') {
                continue;
            }

            unset($parameters[$name]);
        }
        return $parameters;
    }

    public function redisPrefix(): string
    {
        $prefix = $this->string('redis_prefix');
        if (preg_match('/^[A-Za-z0-9:_.\/-]{1,128}$/D', $prefix) !== 1) {
            throw new \RuntimeException('A nonempty Redis cache prefix without glob characters is required.');
        }
        return $prefix;
    }
}
