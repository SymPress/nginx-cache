<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Purge\PurgeRules;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

final class PurgeRulesTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['sympress_nginx_cache_test_options'], $GLOBALS['sympress_nginx_cache_test_permalinks']);
    }

    public function testEditDeleteAndCommentScopesAreIndependent(): void
    {
        $GLOBALS['sympress_nginx_cache_test_permalinks'][12] = 'https://example.test/post/';
        $rules = new PurgeRules(new CompatibilitySettings(new WordPressCacheSettings('/unused')));
        $urls = ['https://example.test/', 'https://example.test/post/', 'https://example.test/category/news/'];
        self::assertSame($urls, $rules->filter('save_post', [12], $urls, [12]));
        $GLOBALS['sympress_nginx_cache_test_options'][CompatibilitySettings::PREFIX . 'purge_home_edit'] = 0;
        $GLOBALS['sympress_nginx_cache_test_options'][CompatibilitySettings::PREFIX . 'purge_archive_edit'] = 0;
        self::assertSame(['https://example.test/post/'], $rules->filter('save_post', [12], $urls, [12]));
        self::assertSame($urls, $rules->filter('before_delete_post', [12], $urls, [12]));
        $GLOBALS['sympress_nginx_cache_test_options'][CompatibilitySettings::PREFIX . 'purge_page_comment_delete'] = 0;
        self::assertSame(['https://example.test/', 'https://example.test/category/news/'], $rules->filter('wp_set_comment_status', [5, 'hold'], $urls, [12]));
        self::assertSame($urls, $rules->filter('wp_set_comment_status', [5, 'approve'], $urls, [12]));
        self::assertSame([], $rules->filter('comment_post', [5, 0], $urls, [12]));
        self::assertTrue($rules->customized());
        self::assertTrue($rules->limited('save_post', [12]));
        self::assertFalse($rules->limited('before_delete_post', [12]));
    }
}
