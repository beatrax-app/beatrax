<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupDecryptionException;
use Modules\Core\Public\Exceptions\BackupFormatException;
use Modules\Core\Public\Exceptions\BackupIoException;
use SodiumException;
use Throwable;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class BackupEncryptor implements FileEncryptor
{
    private const MAGIC = 'BTRXENC1';

    private const CHUNK_SIZE = 65536;

    // The header is unauthenticated and read BEFORE the key is derived, so an
    // unbounded opslimit/memlimit would be a pre-auth DoS (hang / OOM). 10 ops
    // is well above any sane setting (SENSITIVE = 4); the mem cap is
    // libsodium's own SENSITIVE tier (1 GiB).
    private const MAX_OPSLIMIT = 10;

    /**
     * @throws BackupIoException on I/O failure
     * @throws SodiumException on a crypto failure (should not happen for valid input)
     */
    public function encrypt(string $plainPath, string $encPath, string $passphrase): void
    {
        $in = $this->open($plainPath, 'rb', 'read backup source');
        $out = $this->open($encPath, 'wb', 'write encrypted backup', $in);

        try {
            $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $opslimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
            $memlimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
            $key = $this->deriveKey($passphrase, $salt, $opslimit, $memlimit);

            $init = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            sodium_memzero($key);
            /** @var string $state */
            $state = $init[0];
            /** @var string $streamHeader */
            $streamHeader = $init[1];

            $this->writeAll(
                $out,
                self::MAGIC.$salt.pack('V', $opslimit).pack('P', $memlimit).$streamHeader,
                $encPath,
            );

            while (true) {
                $chunk = fread($in, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new BackupIoException('Read error while encrypting backup.');
                }
                $isFinal = feof($in);
                $tag = $isFinal
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                $this->writeAll($out, $ciphertext, $encPath);
                if ($isFinal) {
                    break;
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * @throws BackupFormatException when the file is not one of ours, or its header is not readable
     * @throws BackupDecryptionException on a wrong passphrase, tampering, or truncation
     * @throws BackupIoException on I/O failure
     */
    public function decrypt(string $encPath, string $plainPath, string $passphrase): void
    {
        $in = $this->open($encPath, 'rb', 'read encrypted backup');
        // Decrypt into a temp sibling and atomically rename only after the
        // FINAL tag is verified, so a wrong passphrase / tamper / truncation
        // never leaves partial (but valid-looking) plaintext at $plainPath.
        $tmpPath = $plainPath.'.part';
        $out = $this->open($tmpPath, 'wb', 'write decrypted backup', $in);

        try {
            if ($this->readExactly($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new BackupFormatException('Not a beatrax encrypted backup (bad file header).');
            }
            $salt = $this->readExactly($in, SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $opslimit = $this->unpackUint('V', $this->readExactly($in, 4));
            $memlimit = $this->unpackUint('P', $this->readExactly($in, 8));
            $streamHeader = $this->readExactly($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            $key = $this->deriveKey($passphrase, $salt, $opslimit, $memlimit);
            try {
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $key);
            } catch (SodiumException $e) {
                throw new BackupFormatException('Could not start decryption — the backup header is invalid.', 0, $e);
            } finally {
                sodium_memzero($key);
            }

            $cipherChunk = self::CHUNK_SIZE + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            $sawFinal = false;
            while (true) {
                $block = fread($in, $cipherChunk);
                if ($block === false) {
                    throw new BackupIoException('Read error while decrypting backup.');
                }
                if ($block === '') {
                    break;
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block);
                if ($result === false) {
                    throw new BackupDecryptionException('Decryption failed — wrong passphrase or the backup is corrupted.');
                }
                /** @var string $plain */
                $plain = $result[0];
                /** @var int $tag */
                $tag = $result[1];
                $this->writeAll($out, $plain, $tmpPath);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $sawFinal = true;
                    break;
                }
            }

            if (! $sawFinal) {
                throw new BackupDecryptionException('Decryption failed — the backup is truncated.');
            }
        } catch (Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($tmpPath);
            throw $e;
        }

        fclose($in);
        fclose($out);
        if (! @rename($tmpPath, $plainPath)) {
            @unlink($tmpPath);
            throw new BackupIoException(sprintf('Could not finalize the decrypted backup at %s.', $plainPath));
        }
    }

    private function deriveKey(string $passphrase, string $salt, int $opslimit, int $memlimit): string
    {
        // Reject out-of-range KDF parameters BEFORE deriving — the header they
        // come from is attacker-controllable, and an unbounded memlimit/opslimit
        // would let a corrupt/hostile file OOM or hang the process pre-auth.
        if ($opslimit <= 0 || $opslimit > self::MAX_OPSLIMIT
            || $memlimit <= 0 || $memlimit > SODIUM_CRYPTO_PWHASH_MEMLIMIT_SENSITIVE) {
            throw new BackupFormatException('The backup header requests Argon2id parameters outside the accepted range.');
        }

        // The 256-bit (KEYBYTES) output is the quantum-safety floor; it is a
        // compile-time libsodium constant, asserted in BackupEncryptorTest.
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $passphrase,
            $salt,
            $opslimit,
            $memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    /**
     * @param  resource|null  $closeOnFail  closed before throwing, so a half-open pair cannot leak
     * @return resource
     */
    private function open(string $path, string $mode, string $what, mixed $closeOnFail = null)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            if (is_resource($closeOnFail)) {
                fclose($closeOnFail);
            }
            throw new BackupIoException(sprintf('Cannot %s: %s', $what, $path));
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function writeAll($handle, string $bytes, string $path): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new BackupIoException(sprintf('Write error on %s.', $path));
            }
            $offset += $written;
        }
    }

    /**
     * @param  resource  $handle
     */
    private function readExactly($handle, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $part = fread($handle, max(1, $length - strlen($buffer)));
            if ($part === false || $part === '') {
                throw new BackupFormatException('The encrypted backup ended unexpectedly (truncated header).');
            }
            $buffer .= $part;
        }

        return $buffer;
    }

    /**
     * @param  'V'|'P'  $format
     */
    private function unpackUint(string $format, string $bytes): int
    {
        $unpacked = unpack($format, $bytes);
        if ($unpacked === false || ! isset($unpacked[1]) || ! is_int($unpacked[1])) {
            throw new BackupFormatException('The encrypted backup header is malformed.');
        }

        return $unpacked[1];
    }
}
