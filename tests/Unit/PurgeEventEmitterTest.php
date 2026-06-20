<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\EventDispatcher\Contract\EventDispatcherInterface;
use SymPress\NginxCache\Event\PurgeCompletedEvent;
use SymPress\NginxCache\Event\PurgeFailedEvent;
use SymPress\NginxCache\Purge\PurgeEventEmitter;
use SymPress\NginxCache\Value\PurgeResult;

final class PurgeEventEmitterTest extends TestCase
{
    public function testItDispatchesTypedCompletedEvents(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $result = PurgeResult::success('/cache', 3, 0.1);

        (new PurgeEventEmitter($dispatcher))->emit($result);

        self::assertCount(1, $dispatcher->events);
        self::assertInstanceOf(PurgeCompletedEvent::class, $dispatcher->events[0]);
        self::assertSame($result, $dispatcher->events[0]->result);
    }

    public function testItDispatchesTypedFailedEvents(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $result = PurgeResult::failure('/cache', 'Nope.');

        (new PurgeEventEmitter($dispatcher))->emit($result);

        self::assertCount(1, $dispatcher->events);
        self::assertInstanceOf(PurgeFailedEvent::class, $dispatcher->events[0]);
        self::assertSame($result, $dispatcher->events[0]->result);
    }
}

final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
