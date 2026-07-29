<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

// Whole-file encryption under a passphrase. An interface rather than the
// concrete encryptor because two callers wrap it in a catch that translates a
// libsodium failure — with a final class behind it, those catches cannot be
// reached from a test.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
interface FileEncryptor
{
    public function encrypt(string $plainPath, string $encPath, string $passphrase): void;

    public function decrypt(string $encPath, string $plainPath, string $passphrase): void;
}
