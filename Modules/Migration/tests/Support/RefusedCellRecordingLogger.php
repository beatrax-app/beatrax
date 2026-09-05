<?php

declare(strict_types=1);

namespace Modules\Migration\Tests\Support;

use Psr\Log\AbstractLogger;
use RuntimeException;

// Bound in place of the container's logger so a test can read the context a
// catch wrote, rather than the file Monolog would have written it to.
final class RefusedCellRecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param  array<mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }

    /**
     * @return array<mixed>
     */
    public function parseFailureContext(): array
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], 'parse/stage failed')) {
                return $record['context'];
            }
        }

        throw new RuntimeException('nothing logged a parse/stage failure');
    }
}
