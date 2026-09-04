<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\SafeDate;
use Modules\Core\Public\Support\SecretFileMode;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCredentialsException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @link ../../../../.docs/features/open-banking/secrets-at-rest.md
 */
class OpenBankingSecretsRepository
{
    // Relative to the storage/app root that UserDataPathService::appPath()
    // resolves (respecting NATIVEPHP_STORAGE_PATH) — no leading `app/`.
    private const string PATH_RELATIVE = 'secrets/open-banking.json';

    public function __construct(
        private readonly Filesystem $files,
        private readonly SecretShield $shield,
        // Second at-rest layer: keeps the file ciphertext on the targets where
        // SecretShield binds to the identity PassthroughSecretShield.
        private readonly Encrypter $encrypter,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    public function hasApplication(): bool
    {
        $data = $this->readAll();

        return self::stringOrNull($data['application_id'] ?? null) !== null
            && self::stringOrNull($data['private_key_pem'] ?? null) !== null;
    }

    public function save(OpenBankingCredentials $credentials): void
    {
        $this->guardSingleUser();

        $this->writeAtomic([
            'application_id' => $credentials->applicationId,
            'private_key_pem' => $credentials->privateKeyPem,
            'session_id' => $credentials->sessionId,
            'consent_expires_at' => $credentials->consentExpiresAt?->toAtomString(),
            'bank_sca_host' => $credentials->bankScaHost,
            'institution_id' => $credentials->institutionId,
        ]);
    }

    // Gated on the private key alone, unlike hasApplication(): the wizard writes
    // the key first and must load() the half-written file to merge in the id.
    public function load(): ?OpenBankingCredentials
    {
        $data = $this->readAll();

        $privateKeyPem = self::stringOrNull($data['private_key_pem'] ?? null);
        if ($privateKeyPem === null) {
            return null;
        }

        $applicationId = self::stringOrNull($data['application_id'] ?? null) ?? '';

        return new OpenBankingCredentials(
            applicationId: $applicationId,
            privateKeyPem: $privateKeyPem,
            sessionId: self::stringOrNull($data['session_id'] ?? null),
            consentExpiresAt: self::toDateTime($data['consent_expires_at'] ?? null),
            bankScaHost: self::stringOrNull($data['bank_sca_host'] ?? null),
            institutionId: self::stringOrNull($data['institution_id'] ?? null),
        );
    }

    // Fabricating OpenBankingCredentials only here is what lets the arch guard
    // assert a single credential source for the module.
    public function loadOrThrow(): OpenBankingCredentials
    {
        $credentials = $this->load();
        if ($credentials === null) {
            throw OpenBankingCredentialsException::notConfigured();
        }

        return $credentials;
    }

    public function clear(): void
    {
        $absolute = $this->absolutePath();
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

    protected function userCount(): ?int
    {
        try {
            return User::count();
        } catch (Throwable) {
            return null;
        }
    }

    private function guardSingleUser(): void
    {
        $count = $this->userCount();
        if ($count !== null && $count > 1) {
            $this->logger->warning(
                'OpenBankingSecretsRepository: writing the single global secrets file while '
                .'more than one user account exists. This store has no per-user isolation '
                .'yet — per-user or per-connection secret keying is required before a '
                .'second user can safely use open banking.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readAll(): array
    {
        $absolute = $this->absolutePath();
        $raw = $this->files->exists($absolute) ? $this->files->get($absolute) : '';
        if ($raw === '') {
            return [];
        }

        $revealed = $this->shield->reveal($raw);
        $json = $this->decryptAtRest($revealed);

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // Logged here rather than left to the handler: the settings page
            // answers this one on screen now, so nothing above would record
            // that the file on disk is the part that needs repairing.
            $this->logger->warning(
                'OpenBankingSecretsRepository: the secrets file could not be parsed.',
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

    // A file written before the APP_KEY layer holds plain JSON and raises
    // DecryptException; reading it through lets the next save() re-encrypt it.
    private function decryptAtRest(string $revealed): string
    {
        try {
            $plaintext = $this->encrypter->decrypt($revealed, false);
        } catch (DecryptException) {
            return $revealed;
        }

        return is_string($plaintext) ? $plaintext : $revealed;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeAtomic(array $data): void
    {
        $absolute = $this->absolutePath();
        $this->ensureSecretsDirectory(dirname($absolute));

        $ciphertext = $this->encrypter->encrypt($this->encodePayload($data, $absolute), false);
        $bytes = $this->shield->protect($ciphertext);

        $tmp = $absolute.'.tmp';
        $this->writeTempFile($tmp, $bytes);

        if (! $this->performRename($tmp, $absolute)) {
            @unlink($tmp);
            throw new SecretsWriteFailed(
                "OpenBankingSecretsRepository: atomic rename failed from {$tmp} to {$absolute}."
            );
        }
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
                "OpenBankingSecretsRepository: could not create parent directory {$dir}."
            );
        }
        if (! @chmod($dir, SecretFileMode::DIRECTORY)) {
            throw new SecretsWriteFailed(
                "OpenBankingSecretsRepository: failed to chmod 0700 on newly-created secrets directory {$dir}."
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
                "OpenBankingSecretsRepository: failed to encode payload for {$absolute} ({$e->getMessage()}).",
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
                "OpenBankingSecretsRepository: could not open temp file at {$tmp}."
            );
        }

        try {
            @flock($fp, LOCK_EX);
            $written = @fwrite($fp, $bytes);
            if ($written === false || $written !== strlen($bytes)) {
                throw new SecretsWriteFailed(
                    "OpenBankingSecretsRepository: short write to temp file at {$tmp}."
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
                    "OpenBankingSecretsRepository: failed to chmod temp file at {$tmp}."
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
                "OpenBankingSecretsRepository: unexpected failure writing {$tmp}.",
                previous: $e,
            );
        } finally {
            umask($prevUmask);
        }
    }

    private function absolutePath(): string
    {
        return UserDataPathService::appPath(self::PATH_RELATIVE);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function toDateTime(mixed $value): ?CarbonImmutable
    {
        return is_string($value) ? SafeDate::parseOrNull($value) : null;
    }
}
