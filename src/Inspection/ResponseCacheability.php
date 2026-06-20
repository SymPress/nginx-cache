<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Inspection;

final readonly class ResponseCacheability
{
    /**
     * @param array<string, list<string>> $headers
     * @return list<string>
     */
    public function blockingReasons(array $headers, ?int $statusCode): array
    {
        $reasons = [];

        if ($statusCode !== null && !in_array($statusCode, [200, 301, 404], true)) {
            $reasons[] = 'status-code';
        }

        foreach ($this->values($headers, 'set-cookie') as $value) {
            if (trim($value) !== '') {
                $reasons[] = 'set-cookie';
                break;
            }
        }

        foreach ($this->values($headers, 'cache-control') as $value) {
            $value = strtolower($value);

            foreach (['private', 'no-store', 'no-cache'] as $directive) {
                if (!str_contains($value, $directive)) {
                    continue;
                }

                $reasons[] = 'cache-control:' . $directive;
            }
        }

        foreach ($this->values($headers, 'x-accel-expires') as $value) {
            if (trim($value) !== '0') {
                continue;
            }

            $reasons[] = 'x-accel-expires:0';
        }

        foreach ($this->values($headers, 'vary') as $value) {
            if (trim($value) !== '*') {
                continue;
            }

            $reasons[] = 'vary:*';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param array<string, list<string>> $headers
     * @return list<string>
     */
    private function values(array $headers, string $name): array
    {
        $values = $headers[strtolower($name)] ?? [];

        return is_array($values) ? $values : [];
    }
}
