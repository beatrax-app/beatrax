<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use RuntimeException;

// The passphrase opened the archive and the bytes inside it are not a sound
// database. Distinct from BackupFormatException, which is reached before the
// passphrase is: there the reader picked the wrong file and another file is
// the answer, here the backup itself is damaged and an earlier one is.
final class BackupContentsUnreadableException extends RuntimeException {}
