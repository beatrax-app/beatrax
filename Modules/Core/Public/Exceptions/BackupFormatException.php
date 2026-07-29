<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// The file is not a beatrax encrypted backup, or its header does not
// describe one this build can open. Distinct from a decryption failure:
// the passphrase was never reached, so offering to retype it would send
// the user down the wrong path.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class BackupFormatException extends RuntimeException {}
