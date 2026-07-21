<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

// Shared single-line + length-capped message normaliser for provider
// error strings that may land in a session flash, a queue log, or the
// UI (default cap 300 bytes — long enough for the actual provider
// hint, short enough to fit a flash payload).
final class SafeMessage
{
    // Reduces a raw provider message to a single line capped at $max
    // bytes; consecutive whitespace collapses to one space so the
    // surface is consistently single-line regardless of the original
    // formatting.
    public static function cap(string $raw, int $max = 300): string
    {
        $oneLine = (string) preg_replace('/\s+/', ' ', $raw);

        return substr($oneLine, 0, $max);
    }
}
