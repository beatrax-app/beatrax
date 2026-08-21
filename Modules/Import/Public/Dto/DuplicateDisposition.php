<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Import\Public\Enums\PreviewRowStatus;

/**
 * @see FingerprintDisposition
 */
final class DuplicateDisposition extends FingerprintDisposition
{
    public function status(): PreviewRowStatus
    {
        return PreviewRowStatus::Duplicate;
    }
}
