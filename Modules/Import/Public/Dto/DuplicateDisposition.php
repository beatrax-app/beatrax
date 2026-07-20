<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

/**
 * @see FingerprintDisposition
 */
final class DuplicateDisposition extends FingerprintDisposition
{
    public function status(): string
    {
        return 'duplicate';
    }
}
