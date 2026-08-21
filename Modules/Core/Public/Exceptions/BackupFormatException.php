<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// The file is not a Beatrax encrypted backup, or its header does not
// describe one this build can open. Distinct from a decryption failure:
// the passphrase was never reached, so offering to retype it would send
// the user down the wrong path.
final class BackupFormatException extends RuntimeException {}
