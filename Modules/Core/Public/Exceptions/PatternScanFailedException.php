<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// PCRE stopped part-way through — a JIT stack, backtrack or recursion limit,
// or a subject the pattern's encoding cannot read. Raised rather than folded
// into an empty match set, because the two are the same value in PHP and a
// caller that cannot tell them apart reports a clean scan of nothing.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-regex-that-never-ran-read-as-no-match
 */
final class PatternScanFailedException extends RuntimeException
{
    public function __construct(public readonly string $pattern, string $reason)
    {
        parent::__construct(
            'The pattern `'.$pattern.'` stopped reading before it finished: '.$reason.
            '. An unfinished scan is not the same answer as a scan that found nothing.',
        );
    }
}
