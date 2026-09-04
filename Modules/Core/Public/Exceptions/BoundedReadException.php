<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// The refusal a bounded read raises instead of letting a payload somebody else
// sized become this device's heap. Callers catch it per item, so one oversized
// message costs that message and nothing else.
final class BoundedReadException extends RuntimeException
{
    public static function tooLarge(string $subject, int $bytes, int $maxBytes): self
    {
        return new self("Refusing to read {$subject} whole: {$bytes} bytes is past the {$maxBytes}-byte ceiling.");
    }

    public static function unmeasurable(string $subject): self
    {
        return new self("Refusing to read {$subject} whole: its size could not be determined.");
    }

    public static function unreadable(string $subject): self
    {
        return new self("Refusing to read {$subject} whole: the bytes could not be read back.");
    }
}
