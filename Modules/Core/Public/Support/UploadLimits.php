<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

final class UploadLimits
{
    public const int MAX_KB = 10240;

    public const int MAX_BYTES = self::MAX_KB * 1024;

    // One mail message held whole in memory, wherever it enters: a dropped-in
    // .eml, one message carved out of an .mbox, a raw fetch from Gmail or from
    // Graph. Everything the providers deliver sits well inside it, so a
    // crafted giant is refused rather than left to exhaust the device.
    public const int MAX_MESSAGE_BYTES = 25 * 1024 * 1024;
}
