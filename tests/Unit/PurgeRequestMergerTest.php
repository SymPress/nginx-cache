<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Purge\PurgeRequestMerger;
use SymPress\NginxCache\Value\PurgeMode;
use SymPress\NginxCache\Value\PurgeRequest;

final class PurgeRequestMergerTest extends TestCase
{
    public function testDelegatedUrlRequestsNeverBecomeFullPurgesThroughCoalescing(): void
    {
        $urls = array_map(static fn (int $id): string => 'https://example.test/' . $id, range(1, 1000));
        $request = PurgeRequest::urls($urls, allowFullPurge: false);
        $restored = PurgeRequest::fromArray($request->withPrewarm()->asDryRun()->toArray());
        $merged = (new PurgeRequestMerger())->merge([$restored, PurgeRequest::urls(['https://example.test/extra'])]);
        self::assertCount(2, $merged);
        self::assertSame(['https://example.test/extra'], $merged[0]->urls);
        self::assertTrue($merged[0]->allowFullPurge);
        self::assertFalse($merged[1]->requiresFullPurge());
        self::assertFalse($merged[1]->allowFullPurge);
        self::assertTrue($merged[1]->dryRun);
        self::assertCount(1000, $merged[1]->urls);
    }

    public function testDelegatedUrlsNeverCollapseAPrivilegedRequestIntoAFullPurge(): void
    {
        $urls = static fn (int $offset, int $count): array => array_map(static fn (int $id): string => 'https://example.test/?x=' . $id, range($offset, $offset + $count - 1));
        $delegated = PurgeRequest::urls($urls(0, 10_000), allowFullPurge: false);
        $merger = new PurgeRequestMerger();

        $queue = $merger->merge([$delegated, PurgeRequest::urls($urls(10_000, 1), allowFullPurge: false)]);
        self::assertCount(1, $queue);
        self::assertFalse($queue[0]->requiresFullPurge());
        self::assertCount(10_001, $queue[0]->urls);

        $queue = $merger->merge([$delegated, PurgeRequest::urls([...$urls(10_000, 2), 'https://example.test/?x=1'])]);
        self::assertCount(2, $queue);
        self::assertSame(['https://example.test/?x=10000', 'https://example.test/?x=10001', 'https://example.test/?x=1'], $queue[0]->urls);
        self::assertTrue($queue[0]->allowFullPurge);
        self::assertCount(9_999, $queue[1]->urls, 'URLs already covered by the privileged item are not repeated.');
        self::assertFalse($queue[1]->allowFullPurge);
    }

    public function testPrivilegedUrlsAloneStillCollapseIntoAFullPurgeThatCoversDelegatedWork(): void
    {
        $urls = array_map(static fn (int $id): string => 'https://example.test/' . $id, range(0, PurgeRequestMerger::MAX_URLS));
        $queue = (new PurgeRequestMerger())->merge([
            PurgeRequest::urls(['https://example.test/delegated'], 'editor', allowFullPurge: false, affectedUrls: ['https://example.test/delegated']),
            PurgeRequest::urls($urls, 'bulk-import'),
        ]);

        self::assertCount(1, $queue);
        self::assertTrue($queue[0]->requiresFullPurge());
        self::assertSame('editor, bulk-import', $queue[0]->reason);
        self::assertSame(['https://example.test/delegated'], $queue[0]->affectedUrls);
    }

    public function testItMergesUrlRequestsWithoutDroppingDryRun(): void
    {
        $merged = (new PurgeRequestMerger())->merge([
            PurgeRequest::urls(['https://example.test/a'], 'save_post', 'test', true, false, ['post:1']),
            PurgeRequest::urls(['https://example.test/b'], 'edited_term', 'test', true, true, ['term:2']),
        ]);

        self::assertCount(1, $merged);
        self::assertSame(PurgeMode::Urls, $merged[0]->mode);
        self::assertTrue($merged[0]->dryRun);
        self::assertTrue($merged[0]->prewarm);
        self::assertSame(['https://example.test/a', 'https://example.test/b'], $merged[0]->urls);
        self::assertSame(['post:1', 'term:2'], $merged[0]->tags);
    }

    public function testItFallsBackToFullPurgeWhenUrlLimitWouldDropUrls(): void
    {
        $urls = [];

        for ($index = 0; $index <= PurgeRequestMerger::MAX_URLS; ++$index) {
            $urls[] = sprintf('https://example.test/%d', $index);
        }

        $merged = (new PurgeRequestMerger())->merge([
            PurgeRequest::urls($urls, 'bulk-import', 'test'),
        ]);

        self::assertCount(1, $merged);
        self::assertSame(PurgeMode::Full, $merged[0]->mode);
        self::assertSame([], $merged[0]->urls);
    }
}
