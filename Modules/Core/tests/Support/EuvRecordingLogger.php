<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Psr\Log\NullLogger;
use Stringable;

// Captures warning messages so the two "cannot verify" reasons stay distinguishable.
final class EuvRecordingLogger extends NullLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->warnings[] = (string) $message;
    }
}
