<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Config\MultisiteMapGenerator;
use Symfony\Component\Filesystem\Filesystem;

final class MultisiteMapGeneratorTest extends TestCase
{
    public function testSubdomainsAndLongestSubdirectoryPrefix(): void
    {
        $generator = new MultisiteMapGenerator(new Filesystem());
        $entries = [['domain' => 'example.test', 'path' => '/', 'blog_id' => 1], ['domain' => 'news.example.test', 'path' => '/news/', 'blog_id' => 2]];
        self::assertStringContainsString('news.example.test 2;', $generator->render($entries, true));
        $map = $generator->render($entries, false);
        self::assertStringContainsString('map $uri $sympress_blog_id', $map);
        self::assertLessThan(strpos($map, '~^/ 1;'), strpos($map, '~^/news/ 2;'));
    }

    public function testConfigurationInjectionIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new MultisiteMapGenerator(new Filesystem()))->render([['domain' => 'example.test; include secret;', 'path' => '/', 'blog_id' => 1]], true);
    }

    public function testAmbiguousDomainMappingIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new MultisiteMapGenerator(new Filesystem()))->render([['domain' => 'example.test', 'path' => '/', 'blog_id' => 1], ['domain' => 'example.test', 'path' => '/other/', 'blog_id' => 2]], true);
    }
}
