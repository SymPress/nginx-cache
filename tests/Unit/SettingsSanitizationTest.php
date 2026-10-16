<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SettingsSanitizationTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__ . '/Fixtures/settings-functions.php';
    }

    public function testSettingsCallbacksPreserveAlreadyUnslashedWordPressValues(): void
    {
        $settings = new WordPressCacheSettings('/fixture');
        $values = [
            'sanitizePath'     => '/var/cache/nginx/owner\\cache',
            'sanitizeTextarea' => "^/private/.*\\.pdf$\n^/account/\\d+$\nTestBot\\/2\\.0",
            'sanitizeSecret'   => 'token\\with\\slashes',
        ];

        foreach ($values as $method => $value) {
            // options.php removes request slashes before invoking registered callbacks.
            $submitted = wp_unslash(addslashes($value));
            self::assertSame($value, $settings->$method($submitted));
            self::assertSame($value, $settings->$method($settings->$method($submitted)));
        }
    }

    public function testAdditionalPurgeUrlsRetainSeparateLines(): void
    {
        $settings = new CompatibilitySettings(new WordPressCacheSettings('/fixture'));
        $urls = "/extra-one/\n/extra-two/";

        self::assertSame($urls, $settings->sanitize('additional_purge_urls', $urls));
        self::assertSame(
            $urls,
            $settings->sanitize('additional_purge_urls', $settings->sanitize('additional_purge_urls', $urls)),
        );
    }
}
