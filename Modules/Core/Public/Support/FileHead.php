<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// How much of a file is read before deciding what it is. The import sniffer
// and the receipt shape reader each held their own copy of the number, so a
// header that moved past one reader's window still fit the other's.
final class FileHead
{
    public const int BYTES = 8192;
}
