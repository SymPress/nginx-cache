<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractCacheCommand extends Command implements WpCliCommand
{
    final public function getWpCliName(): string
    {
        $name = (string) $this->getName();

        return str_starts_with($name, 'nginx-cache:')
            ? substr($name, strlen('nginx-cache:'))
            : $name;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    final public function __invoke(array $args = [], array $assocArgs = []): void
    {
        $exitCode = $this->runWpCli($args, $assocArgs);

        if ($exitCode === Command::SUCCESS || !class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::halt($exitCode);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    abstract protected function runWpCli(array $args, array $assocArgs): int;

    protected function line(string $message, ?OutputInterface $output = null): void
    {
        if ($output instanceof OutputInterface) {
            $output->writeln($message);

            return;
        }

        if (!class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::line($message);
    }

    protected function log(string $message, ?OutputInterface $output = null): void
    {
        if ($output instanceof OutputInterface) {
            $output->writeln($message);

            return;
        }

        if (!class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::log($message);
    }

    protected function success(string $message, ?OutputInterface $output = null): void
    {
        if ($output instanceof OutputInterface) {
            $output->writeln(sprintf('<info>%s</info>', $message));

            return;
        }

        if (!class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::success($message);
    }

    protected function warning(string $message, ?OutputInterface $output = null): void
    {
        if ($output instanceof OutputInterface) {
            $output->writeln(sprintf('<comment>%s</comment>', $message));

            return;
        }

        if (!class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::warning($message);
    }

    protected function error(string $message, ?OutputInterface $output = null): int
    {
        if ($output instanceof OutputInterface) {
            $output->writeln(sprintf('<error>%s</error>', $message));

            return Command::FAILURE;
        }

        if (class_exists('WP_CLI')) {
            \WP_CLI::error($message);
        }

        return Command::FAILURE;
    }

    /** @param array<string, mixed> $assocArgs */
    protected function flag(array $assocArgs, string $name): bool
    {
        $value = $assocArgs[$name] ?? false;

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return !in_array(strtolower($value), ['', '0', 'false', 'no'], true);
        }

        return (bool) $value;
    }

    /** @return list<string> */
    protected function strings(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    static fn (mixed $item): string => is_string($item) ? trim($item) : '',
                    $value,
                ),
                static fn (string $item): bool => $item !== '',
            ),
        );
    }

    protected function json(mixed $value): string
    {
        if (function_exists('wp_json_encode')) {
            return (string) wp_json_encode($value, JSON_PRETTY_PRINT);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WP fallback for Symfony Console context.
        return (string) json_encode($value, JSON_PRETTY_PRINT);
    }
}
