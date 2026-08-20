<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Exceptions;

use RuntimeException;

// Adding EUR to USD is a bug in the caller, not a rounding question, so it
// throws. Ours rather than the money library's: the type a caller catches is
// part of Money's contract, and a library swap must not change it.
final class CurrencyMismatchException extends RuntimeException
{
    public static function between(string $left, string $right): self
    {
        return new self("Cannot combine {$left} with {$right}: amounts must share a currency.");
    }
}
