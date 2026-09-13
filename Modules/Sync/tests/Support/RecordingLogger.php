<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

// Which phase a session ended in is only ever said in a log line: handleClient()
// swallows every failure by design, so a test that asserted nothing threw would
// pass for a handshake that never completed.
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $lines = [];

    /** @param array<string, mixed> $context */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->lines[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(static fn (array $line): string => $line['message'], $this->lines);
    }

    public function said(string $needle): bool
    {
        foreach ($this->messages() as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
