<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Value;

enum PurgeScope: string
{
    case Site = 'site';
    case Network = 'network';
}
