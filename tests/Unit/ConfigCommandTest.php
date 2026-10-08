<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Cli\Command\ConfigCommand;
use SymPress\NginxCache\Config\BypassRuleProvider;
use SymPress\NginxCache\Config\NginxConfigGenerator;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigCommandTest extends TestCase
{
    public function testItExportsAUsableHttpIncludeWithItsConfiguredPath(): void
    {
        $command = $this->command();
        self::assertSame(Command::SUCCESS, $command->execute(['--section' => 'http', '--json' => true]));
        $result = json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('http', $result['context']);
        self::assertSame([], $result['missing_directives']);
        self::assertStringContainsString('fastcgi_cache_path /var/cache/nginx/wordpress ', $result['config']);
        self::assertStringNotContainsString('fastcgi_cache_bypass', $result['config']);
    }

    public function testDefaultOutputStillContainsAllSections(): void
    {
        $command = $this->command();
        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertStringContainsString('fastcgi_cache_path', $command->getDisplay());
        self::assertStringContainsString('location ~*', $command->getDisplay());
        self::assertStringContainsString('fastcgi_cache WORDPRESS;', $command->getDisplay());
    }

    public function testItFailsForInvalidContexts(): void
    {
        $command = $this->command();
        self::assertSame(Command::FAILURE, $command->execute(['--section' => 'unknown']));
        self::assertStringNotContainsString('fastcgi_cache_path', $command->getDisplay());
    }

    public function testItExportsLoggingSeparatelyFromStaticAssetLocations(): void
    {
        $command = $this->command();
        self::assertSame(Command::SUCCESS, $command->execute(['--section' => 'logging']));
        self::assertStringContainsString('access_log /var/log/nginx/sympress-cache-metrics.jsonl', $command->getDisplay());
        self::assertStringNotContainsString('location ', $command->getDisplay());
    }

    private function command(): CommandTester
    {
        $settings = new WordPressCacheSettings('/var/cache/nginx/wordpress');

        return new CommandTester(new ConfigCommand(
            $settings,
            new NginxConfigGenerator($settings, new BypassRuleProvider(), new CacheKeyStrategy()),
        ));
    }
}
