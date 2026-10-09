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
        self::assertFalse($merged[0]->requiresFullPurge());
        self::assertFalse($merged[0]->allowFullPurge);
        self::assertCount(1001, $merged[0]->urls);
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
