<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Value;

final readonly class CacheStatus
{
    public function __construct(
        public string $path,
        public bool $exists,
        public bool $directory,
        public bool $writable,
        public int $files,
        public int $directories,
        public int $bytes,
        public bool $scanComplete,
        public ?string $error = null,
    ) {
    }

    public function available(): bool
    {
        return $this->exists && $this->directory && $this->writable && $this->error === null;
    }

    public function formattedSize(): string
    {
        $bytes = (float) $this->bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        foreach ($units as $unit) {
            if ($bytes < 1024.0 || $unit === 'TB') {
                return sprintf('%s %s', rtrim(rtrim(sprintf('%.1f', $bytes), '0'), '.'), $unit);
            }

            $bytes /= 1024.0;
        }

        return sprintf('%d B', $this->bytes);
    }
}
