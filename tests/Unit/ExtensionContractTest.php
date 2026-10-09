<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Config\BypassRuleProvider;
use SymPress\NginxCache\Filesystem\CachePathValidator;
use SymPress\NginxCache\Hook\AutomaticPurgeSubscriber;
use SymPress\NginxCache\Integration\Polylang\PolylangIntegration;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Layer\CacheLayerCoordinator;
use SymPress\NginxCache\Purge\CacheFileResolver;
use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Purge\CachePurger;
use SymPress\NginxCache\Purge\FullPurgeEndpointDispatcher;
use SymPress\NginxCache\Purge\Prewarmer;
use SymPress\NginxCache\Purge\PurgeEventEmitter;
use SymPress\NginxCache\Purge\PurgeHistoryRepository;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Purge\PurgeQueueRepository;
use SymPress\NginxCache\Purge\PurgeRequestMerger;
use SymPress\NginxCache\Purge\PurgeSideEffectProcessor;
use SymPress\NginxCache\Purge\PurgeSideEffectQueueRepository;
use SymPress\NginxCache\Purge\PurgeUrlCollector;
use SymPress\NginxCache\Remote\CloudflarePurgeDispatcher;
use SymPress\NginxCache\Remote\RemotePurgeDispatcher;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Support\OptionMutex;
use SymPress\NginxCache\Surrogate\CacheTagResolver;
use SymPress\NginxCache\Surrogate\TagIndexRepository;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\CacheProfile;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExtensionContractTest extends TestCase
{
    private WordPressCacheSettings $settings;
    private PurgeUrlCollector $collector;
    private CacheTagResolver $tags;
    private TagIndexRepository $index;
    private CacheClock $clock;
    private OptionMutex $mutex;

    protected function setUp(): void
    {
        require_once __DIR__ . '/PurgeUrlCollectorTest.php';
        require_once dirname(__DIR__) . '/Support/hooks.php';
        $GLOBALS['extension_hooks'] = [];
        $GLOBALS['sympress_nginx_cache_test_options'] = [WordPressCacheSettings::OPTION_AUTO_PURGE => 1];
        $GLOBALS['sympress_nginx_cache_test_posts'] = [42 => (object) ['ID' => 42, 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => 1]];
        $GLOBALS['sympress_nginx_cache_test_permalinks'] = [42 => 'https://example.test/article/'];
        $this->clock = new CacheClock(new MockClock());
        $this->mutex = new OptionMutex(new LockFactory(new InMemoryStore()));
        $this->settings = new WordPressCacheSettings('/tmp/extension-cache');
        $this->tags = new CacheTagResolver();
        $this->index = new TagIndexRepository($this->tags, new UrlPolicy(), $this->clock, $this->mutex);
        $this->collector = new PurgeUrlCollector($this->tags, $this->index, new UrlPolicy(), $this->settings);
    }

    public function testPurgeUrlAndTagFiltersValidateTheirReturnedValues(): void
    {
        add_filter('sympress_nginx_cache_purge_urls', function (array $urls, string $hook, array $args): array {
            self::assertSame('save_post', $hook);
            self::assertSame([42], $args);
            return [...$urls, 'https://example.test/translated/', 'https://foreign.test/'];
        }, 10, 3);
        add_filter('sympress_nginx_cache_purge_tags', function (array $tags, string $hook, array $args): array {
            self::assertSame('save_post', $hook);
            self::assertSame([42], $args);
            return [...$tags, 'translation:42', 'site:1', 'invalid tag'];
        }, 10, 3);
        $request = PurgeRequest::urls($this->collector->collect('save_post', [42]), tags: $this->collector->collectTags('save_post', [42]));
        self::assertContains('https://example.test/translated/', $request->urls);
        self::assertNotContains('https://foreign.test/', $request->urls);
        self::assertContains('translation:42', $request->tags);
        self::assertNotContains('site:1', $request->tags);
    }

    public function testObjectTagsAndFullHookContextAreStable(): void
    {
        add_filter('sympress_nginx_cache_post_tags', function (array $tags, int $id): array { self::assertSame(42, $id); return [...$tags, 'translation:42']; }, 10, 2);
        self::assertContains('translation:42', $this->tags->postTags(42));
        $term = (object) ['taxonomy' => 'category', 'slug' => 'news'];
        add_filter('sympress_nginx_cache_term_tags', function (array $tags, int $id, ?object $object) use ($term): array { self::assertSame(7, $id); self::assertSame($term, $object); return [...$tags, 'term_group:7']; }, 10, 3);
        self::assertContains('term_group:7', $this->tags->termTags(7, $term));
        add_filter('sympress_nginx_cache_full_purge_hooks', function (array $hooks, string $hook, array $args): array { self::assertSame('language_changed', $hook); self::assertSame(['de'], $args); return [...$hooks, $hook]; }, 10, 3);
        self::assertTrue($this->collector->requiresFullPurge('language_changed', ['de']));
    }

    public function testBypassAndQueryFiltersReceiveTheSelectedProfile(): void
    {
        foreach (['bypass_cookies' => ['cookies', 'language_cookie'], 'bypass_uris' => ['uris', '^/private/'], 'query_allowlist' => ['query_allowlist', '^lang=de$']] as $suffix => [$field, $extra]) {
            add_filter('sympress_nginx_cache_' . $suffix, function (array $values, string $profile) use ($extra): array { self::assertSame('safe', $profile); return [...$values, $extra]; }, 10, 2);
            self::assertContains($extra, (new BypassRuleProvider($this->settings))->rules(CacheProfile::Safe)[$field]);
        }
    }

    public function testSettingsAndPrewarmFiltersHaveObservableEffects(): void
    {
        add_filter('sympress_nginx_cache_path', static fn (string $path): string => $path . '-custom');
        add_filter('sympress_nginx_cache_excluded_post_types', static fn (array $types): array => [...$types, 'private_catalog']);
        add_filter('sympress_nginx_cache_prewarm_urls', static fn (array $urls): array => [...$urls, 'https://example.test/important/', 'https://foreign.test/']);
        self::assertSame('/tmp/extension-cache-custom', $this->settings->cachePath());
        self::assertContains('private_catalog', $this->settings->excludedPostTypes());
        $prewarmer = new Prewarmer(new MockHttpClient(), $this->settings, new UrlPolicy(), $this->clock);
        self::assertContains('https://example.test/important/', $prewarmer->plan()->urls);
        self::assertNotContains('https://foreign.test/', $prewarmer->plan()->urls);
    }

    public function testKeyFiltersAgreeWithGeneratedAndParsedKeys(): void
    {
        add_filter('sympress_nginx_cache_key_template', static fn (string $template): string => '$scheme$request_method$host$request_uri');
        $strategy = new CacheKeyStrategy();
        self::assertSame('httpsGETexample.test/article/', $strategy->formatKey('https', 'GET', 'example.test', '/article/'));
        self::assertSame('/article/', $strategy->parseKey('httpsGETexample.test/article/')['uri']);
        self::assertSame('httpsGETexample.test/article/', $strategy->candidates('https://example.test/article/')[0]['key']);
        add_filter('sympress_nginx_cache_key_candidates', function (array $candidates, string $scheme, string $host, string $uri): array {
            self::assertSame(['https', 'example.test', '/article/'], [$scheme, $host, $uri]);
            return [[...$candidates[0], 'key' => 'custom-key']];
        }, 10, 4);
        self::assertSame('custom-key', $strategy->candidates('https://example.test/article/')[0]['key']);
    }

    public function testAutomaticActionRegistrationAndVeto(): void
    {
        $http = new MockHttpClient(static fn () => throw new \RuntimeException('Contract test must perform no HTTP.'));
        $policy = new UrlPolicy();
        $fs = new Filesystem();
        $purger = new CachePurger($fs, new CachePathValidator($fs), new CacheFileResolver($this->settings, new CacheKeyStrategy()), new FullPurgeEndpointDispatcher($http, $this->settings, $policy, $this->clock), $this->clock);
        $effects = new PurgeSideEffectProcessor($this->settings, new PurgeSideEffectQueueRepository($this->mutex, $this->clock), new Prewarmer($http, $this->settings, $policy, $this->clock), new CacheLayerCoordinator($this->settings), new RemotePurgeDispatcher($http, $this->settings, $policy, $this->clock), new CloudflarePurgeDispatcher($http, $this->settings, $this->tags, $policy), $this->clock);
        $manager = new CacheManager($this->settings, $purger, new PurgeHistoryRepository($this->clock), new PurgeEventEmitter(), $this->index, $effects);
        $queue = new PurgeQueueProcessor($this->settings, new PurgeQueueRepository(new PurgeRequestMerger(), $this->mutex, $this->clock), $manager, $this->clock);
        $subscriber = new AutomaticPurgeSubscriber($this->settings, $manager, $queue, $this->collector, new PurgeRequestMerger());
        add_filter('sympress_nginx_cache_purge_actions', static fn (array $actions): array => [...$actions, 'language_changed']);
        $subscriber->register();
        self::assertArrayHasKey('language_changed', $GLOBALS['extension_hooks']);
        $called = false;
        add_filter('sympress_nginx_cache_should_purge', function (bool $purge, array $args) use (&$called): bool { self::assertTrue($purge); self::assertSame([42], $args); $called = true; return false; }, 10, 2);
        $subscriber->purgeOnce('save_post', 42);
        $subscriber->flushPending();
        self::assertTrue($called);
        self::assertSame(0, $queue->count());
    }

    public function testResultActionsExposeTheOriginalImmutableResult(): void
    {
        $success = PurgeResult::success('/tmp/cache', 1, 0.01);
        $failure = PurgeResult::failure('/tmp/cache', 'Unavailable');
        $seen = [];
        add_action('sympress_nginx_cache_purged', static function (PurgeResult $result) use (&$seen): void { $seen[] = $result; });
        add_action('sympress_nginx_cache_purge_failed', static function (PurgeResult $result) use (&$seen): void { $seen[] = $result; });
        $emitter = new PurgeEventEmitter();
        $emitter->emit($success);
        $emitter->emit($failure);
        self::assertSame([$success, $failure], $seen);
    }

    public function testAbsentPolylangLeavesEveryPublicHookUnchanged(): void
    {
        $integration = new PolylangIntegration($this->collector);
        self::assertFalse($integration->active());
        $urls = ['https://example.test/article/'];
        self::assertSame($urls, $integration->purgeUrls($urls, 'save_post', [42]));
        self::assertSame(['original'], $integration->postTags(['original'], 42));
        self::assertSame(['original'], $integration->termTags(['original'], 7));
        self::assertSame(['save_post'], $integration->actions(['save_post']));
        self::assertSame(['switch_theme'], $integration->fullHooks(['switch_theme'], 'switch_theme', []));
        self::assertSame(['pll_language'], $integration->cookies(['pll_language']));
        self::assertSame(['^/wp-admin/'], $integration->uris(['^/wp-admin/']));
        self::assertSame(['^$'], $integration->queryAllowlist(['^$']));
        self::assertSame($urls, $integration->prewarmUrls($urls));
    }
}
