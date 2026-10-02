<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Value;

final readonly class QueueRetryState
{
    public const int MAX_ATTEMPTS = 5;
    private const int BASE_DELAY = 60;
    private const int MAX_DELAY = 300;

    public function __construct(
        public int $attempts = 0,
        public int $retryAt = 0,
    ) {
    }

    public static function fromArray(mixed $data): self
    {
        $data = is_array($data) ? $data : [];

        return new self(max(0, (int) ($data['attempts'] ?? 0)), max(0, (int) ($data['retry_at'] ?? 0)));
    }

    public function exhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function ready(int $now): bool
    {
        return !$this->exhausted() && $this->retryAt <= $now;
    }

    public function reserve(int $now): self
    {
        $attempts = min(self::MAX_ATTEMPTS, $this->attempts + 1);
        $delay = min(self::MAX_DELAY, self::BASE_DELAY * (2 ** ($attempts - 1)));

        return new self($attempts, $now + $delay);
    }

    /** @return array{attempts: int, retry_at: int} */
    public function toArray(): array
    {
        return ['attempts' => $this->attempts, 'retry_at' => $this->retryAt];
    }
}
