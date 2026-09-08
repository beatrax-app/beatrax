<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Modules\DevMode\Tests\ is not in the mobile Composer root's autoload-dev —
 * it maps Modules\Mobile\Tests\ and Modules\Sync\Tests\ only — so a Mobile test
 * reaching DevMode's recorder passes at the repository root and dies in the
 * mobile-scoped job.
 */
final class RecordingMobileLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
