<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

/**
 * @see FingerprintDisposition
 */
final class NewRowDisposition extends FingerprintDisposition
{
    public function status(): string
    {
        return 'new';
    }
}
