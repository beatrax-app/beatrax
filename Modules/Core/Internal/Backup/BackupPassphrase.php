<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

// Both export paths encrypt under a passphrase the reader types once and has to
// still have when they need the file back. One floor, named here, because two
// screens asking for the same secret at two different lengths is one of them
// teaching the reader the wrong habit.
final class BackupPassphrase
{
    public const int MIN_LENGTH = 8;
}
