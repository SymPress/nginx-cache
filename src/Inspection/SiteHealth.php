<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Inspection;

use Predis\Client;
use SymPress\NginxCache\Filesystem\CachePathValidator;
use SymPress\NginxCache\Purge\CacheWorker;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Purge\PurgeSideEffectProcessor;
use SymPress\NginxCache\Purge\SiteScopeResolver;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\TagIndexRepository;
use SymPress\NginxCache\Time\CacheClock;

final readonly class SiteHealth
{
    public function __construct(private WordPressCacheSettings $settings, private CompatibilitySettings $backend, private CachePathValidator $validator, private PurgeQueueProcessor $queue, private PurgeSideEffectProcessor $effects, private SiteScopeResolver $scope, private CacheClock $clock, private TagIndexRepository $tags)
    {
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public function tests(array $tests): array
    {
        foreach (['path', 'queues', 'worker', 'redis', 'metrics', 'scope'] as $check) {
            $tests['direct']['sympress_nginx_cache_' . $check] = ['label' => 'Nginx Cache: ' . $check, 'test' => fn (): array => $this->check($check)];
        }
        return $tests;
    }

    /** @return array<string, mixed> */
    public function check(string $name): array
    {
        $backend = $this->backend->string('purge_backend');
        $status = 'good';
        $description = '';
        if ($name === 'path' && $backend === 'local_files') {
            $validation = $this->validator->validate($this->settings->cachePath(), false, true);
            $status = $validation->isValid() ? 'good' : 'critical';
            $description = $validation->firstError() ?? __('The cache path is writable and recognized as a managed Nginx cache.', WordPressCacheSettings::TEXT_DOMAIN);
        } elseif ($name === 'queues') {
            $exhausted = count(array_filter([...$this->queue->inspect(), ...$this->effects->inspect()], static fn (array $task): bool => ($task['exhausted'] ?? false) === true));
            $status = $exhausted > 0 ? 'critical' : 'good';
            $description = sprintf(__('%d cache tasks have exhausted their retries.', WordPressCacheSettings::TEXT_DOMAIN), $exhausted);
        } elseif ($name === 'worker') {
            $heartbeat = max((int) get_option(CacheWorker::HEARTBEAT, 0), $this->scope->isMultisite() ? (int) get_site_option(CacheWorker::HEARTBEAT, 0) : 0);
            $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            $status = $disabled && $heartbeat < $this->clock->timestamp() - 300 ? 'recommended' : 'good';
            $description = $status === 'good' ? __('Cron or a recent worker heartbeat is available.', WordPressCacheSettings::TEXT_DOMAIN) : __('WP-Cron is disabled and no worker has reported in five minutes. Schedule wp nginx-cache work.', WordPressCacheSettings::TEXT_DOMAIN);
        } elseif ($name === 'redis' && $backend === 'redis') {
            try {
                $parameters = [...$this->backend->redisConnection(), 'timeout' => 1.0, 'read_write_timeout' => 1.0];
                $client = new Client($parameters);
                try {
                    $client->executeRaw(['PING']);
                } finally {
                    $client->disconnect();
                }
                $description = __('Redis is reachable.', WordPressCacheSettings::TEXT_DOMAIN);
            } catch (\Throwable) {
                $status = 'critical';
                $description = __('Redis could not be reached within the diagnostic timeout. Check the connection configuration.', WordPressCacheSettings::TEXT_DOMAIN);
            }
        } elseif ($name === 'metrics') {
            $readable = is_file(CacheMetricsReader::logPath()) && is_readable(CacheMetricsReader::logPath());
            $status = $readable ? 'good' : 'recommended';
            $description = $readable ? __('The cache metrics log is readable.', WordPressCacheSettings::TEXT_DOMAIN) : __('Configure the Nginx metrics log to measure the cache hit rate.', WordPressCacheSettings::TEXT_DOMAIN);
        } elseif ($name === 'scope' && !$this->scope->isIsolated()) {
            $scoped = $backend !== 'http' && $this->settings->fullPurgeMode() === 'local_files';
            $scoped = $scoped || (defined('SYMPRESS_NGINX_CACHE_ENDPOINT_SUPPORTS_SITE_SCOPE') && constant('SYMPRESS_NGINX_CACHE_ENDPOINT_SUPPORTS_SITE_SCOPE') === true);
            $status = $scoped ? 'good' : 'critical';
            $description = $scoped ? __('The configured backend supports purges restricted to one site.', WordPressCacheSettings::TEXT_DOMAIN) : __('A shared cache root requires a site-aware full purge endpoint. Site purges fail closed until it is configured.', WordPressCacheSettings::TEXT_DOMAIN);
        }
        $description = $description !== '' ? $description : __('This check does not apply to the current cache backend.', WordPressCacheSettings::TEXT_DOMAIN);
        return ['label' => 'Nginx Cache: ' . $name, 'status' => $status, 'badge' => ['label' => 'Nginx Cache', 'color' => 'green'], 'description' => '<p>' . esc_html($description) . '</p>', 'actions' => '', 'test' => 'sympress_nginx_cache_' . $name];
    }

    /**
     * @param array<string, mixed> $information
     * @return array<string, mixed>
     */
    public function debug(array $information): array
    {
        $data = get_file_data(__DIR__ . '/../../nginx-cache.php', ['version' => 'Version']);
        $values = ['version' => $data['version'], 'backend' => $this->backend->string('purge_backend'), 'profile' => $this->settings->profile()->value, 'path' => $this->settings->cachePath(), 'pending_purges' => $this->queue->count(), 'pending_follow_up' => $this->effects->count(), 'scope' => $this->scope->isIsolated() ? 'isolated-site-root' : 'shared-root-site-key-scan', 'worker_heartbeat' => (int) get_option(CacheWorker::HEARTBEAT, 0), 'tag_index' => wp_json_encode($this->tags->stats())];
        $fields = [];
        foreach ($values as $name => $value) {
            $fields[$name] = ['label' => $name, 'value' => $value];
        }
        $information[WordPressCacheSettings::TEXT_DOMAIN] = ['label' => 'Nginx Cache', 'fields' => $fields];
        return $information;
    }
}
