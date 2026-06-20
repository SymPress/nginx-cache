<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Value;

enum PurgeMode: string
{
    case Full = 'full';
    case Urls = 'urls';
}
