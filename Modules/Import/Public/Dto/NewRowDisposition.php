<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

/**
 * FingerprintDisposition variant returned when no existing transactions
 * row matches the incoming canonical's fingerprint. The Import pipeline
 * proceeds to insert the canonical via the standard recorder action.
 */
final class NewRowDisposition extends FingerprintDisposition
{
    public function status(): string
    {
        return 'new';
    }
}
