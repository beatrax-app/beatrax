<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Support\SecretFileMode;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCredentialsException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @link ../../../../.docs/features/open-banking/secrets-at-rest.md#the-atomic-write
 */
class OpenBankingSecretsFile
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly SecretShield $shield,
        // Second at-rest layer: keeps the file ciphertext on the targets where
        // SecretShield binds to the identity PassthroughSecretShield.
        private readonly Encrypter $encrypter,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    public function exists(string $absolute): bool
    {
        return $this->files->exists($absolute);
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $absolute): array
    {
        $raw = $this->files->exists($absolute) ? $this->files->get($absolute) : '';
        if ($raw === '') {
            return [];
        }

        $json = $this->decryptAtRest($this->shield->reveal($raw));

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // Logged here rather than left to the handler: the settings page
            // answers this one on screen now, so nothing above would record
            // that the file on disk is the part that needs repairing.
            $this->logger->warning(
                'OpenBankingSecretsFile: the secrets file could not be parsed.',
                ['path' => $absolute],
            );

            throw OpenBankingCredentialsException::unreadable($absolute, $e);
        }

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function write(string $absolute, array $data): void
    {
        $this->ensureSecretsDirectory(dirname($absolute));

        $ciphertext = $this->encrypter->encrypt($this->encodePayload($data, $absolute), false);
        $bytes = $this->shield->protect($ciphertext);

        $tmp = $absolute.'.tmp';
        $this->writeTempFile($tmp, $bytes);

        if (! $this->performRename($tmp, $absolute)) {
            @unlink($tmp);
            throw new SecretsWriteFailed(
                "OpenBankingSecretsFile: atomic rename failed from {$tmp} to {$absolute}."
            );
        }
    }

    public function delete(string $absolute): void
    {
        if ($this->files->exists($absolute)) {
            $this->files->delete($absolute);
        }
    }

    // A seam, not an abstraction: tests force the rename-failure branch by
    // overriding this rather than by breaking the real filesystem.
    protected function performRename(string $tmp, string $final): bool
    {
        return @rename($tmp, $final);
    }

    // A file written before the APP_KEY layer holds plain JSON and raises
    // DecryptException; reading it through lets the next write() re-encrypt it.
    private function decryptAtRest(string $revealed): string
    {
        try {
            $plaintext = $this->encrypter->decrypt($revealed, false);
        } catch (DecryptException) {
            return $revealed;
        }

        return is_string($plaintext) ? $plaintext : $revealed;
    }

    private function ensureSecretsDirectory(string $dir): void
    {
        // 0700 on create only: re-chmodding every write would silently undo a
        // widening an operator applied on purpose, e.g. for a backup agent.
        if (is_dir($dir)) {
            return;
        }
        if (! @mkdir($dir, SecretFileMode::DIRECTORY, recursive: true) && ! is_dir($dir)) {
            throw new SecretsWriteFailed(
                "OpenBankingSecretsFile: could not create parent directory {$dir}."
            );
        }
        if (! @chmod($dir, SecretFileMode::DIRECTORY)) {
            throw new SecretsWriteFailed(
                "OpenBankingSecretsFile: failed to chmod 0700 on newly-created secrets directory {$dir}."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function encodePayload(array $data, string $absolute): string
    {
        try {
            return json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new SecretsWriteFailed(
                "OpenBankingSecretsFile: failed to encode payload for {$absolute} ({$e->getMessage()}).",
                previous: $e,
            );
        }
    }

    private function writeTempFile(string $tmp, string $bytes): void
    {
        // Narrowed before fopen so the file is born 0600; otherwise it is
        // world-readable for the window before the explicit chmod below.
        $prevUmask = umask(0077);

        $fp = @fopen($tmp, 'wb');
        if ($fp === false) {
            umask($prevUmask);
            throw new SecretsWriteFailed(
                "OpenBankingSecretsFile: could not open temp file at {$tmp}."
            );
        }

        try {
            @flock($fp, LOCK_EX);
            $written = @fwrite($fp, $bytes);
            if ($written === false || $written !== strlen($bytes)) {
                throw new SecretsWriteFailed(
                    "OpenBankingSecretsFile: short write to temp file at {$tmp}."
                );
            }
            @fflush($fp);
            if (function_exists('fsync')) {
                @fsync($fp);
            }
            @flock($fp, LOCK_UN);
            @fclose($fp);
            $fp = null;

            if (! @chmod($tmp, SecretFileMode::FILE)) {
                throw new SecretsWriteFailed(
                    "OpenBankingSecretsFile: failed to chmod temp file at {$tmp}."
                );
            }
        } catch (Throwable $e) {
            if (is_resource($fp)) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
            @unlink($tmp);
            if ($e instanceof SecretsWriteFailed) {
                throw $e;
            }
            throw new SecretsWriteFailed(
                "OpenBankingSecretsFile: unexpected failure writing {$tmp}.",
                previous: $e,
            );
        } finally {
            umask($prevUmask);
        }
    }
}
