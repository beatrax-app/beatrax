<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

/**
 * FingerprintDisposition variant returned when an existing transactions
 * row already carries the same fingerprint and the incoming source_ref
 * is no stronger than the stored one. The canonical is discarded and the
 * preview row is rendered with a DUPLICATE badge.
 */
final class DuplicateDisposition extends FingerprintDisposition
{
    public function status(): string
    {
        return 'duplicate';
    }
}
