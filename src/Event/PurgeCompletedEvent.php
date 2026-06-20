<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Event;

use SymPress\EventDispatcher\Event\AbstractEvent;
use SymPress\NginxCache\Value\PurgeResult;

final readonly class PurgeCompletedEvent extends AbstractEvent
{
    public function __construct(
        public PurgeResult $result,
    ) {
    }
}
