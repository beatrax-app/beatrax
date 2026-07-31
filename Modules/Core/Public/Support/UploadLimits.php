<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

final class UploadLimits
{
    public const int MAX_KB = 10240;

    public const int MAX_BYTES = self::MAX_KB * 1024;
}
