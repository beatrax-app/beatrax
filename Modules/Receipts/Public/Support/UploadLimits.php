<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Support;

final class UploadLimits
{
    // One receipt message read whole into memory is kilobytes; even
    // attachment-heavy mail stays well under this. It bounds both a
    // dropped-in .eml and a single message carved out of an .mbox, so a
    // crafted giant file is quarantined instead of OOM-killing the worker.
    public const MAX_MESSAGE_BYTES = 25 * 1024 * 1024;
}
