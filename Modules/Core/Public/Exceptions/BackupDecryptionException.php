<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// The header parsed but the contents would not decrypt or authenticate.
// Deliberately does not distinguish a wrong passphrase from a tampered
// file: the AEAD tag fails identically for both, and guessing which
// would tell an attacker whether their passphrase was close.
final class BackupDecryptionException extends RuntimeException {}
