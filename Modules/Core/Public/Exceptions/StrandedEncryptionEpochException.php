<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// `current_epoch` is recorded as committed but the GDK keyring holds no
// usable key for it — a crash in the commit-then-finalize window stranded
// the epoch. Sensitive writes must stop until the keyring file is
// finalized or restored, never silently continue over plaintext.
final class StrandedEncryptionEpochException extends RuntimeException {}
