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

    // For a secret that is ALREADY a uniformly random key rather than a
    // human passphrase. A password-hashing KDF exists to make brute-forcing a
    // low-entropy secret expensive; against 256 random bits it buys nothing
    // and costs ~500ms per read. Never call this with a user-chosen secret.
    public function encryptWithKey(string $plainPath, string $encPath, string $key): void;

    public function decrypt(string $encPath, string $plainPath, string $passphrase): void;

    // The KDF cost stored in an encrypted file's own header, so a caller can
    // tell a cheap key-encrypted file from a costly passphrase one.
    /**
     * @return array{0: int, 1: int} opslimit, memlimit
     */
    public function kdfParams(string $encPath): array;
}
