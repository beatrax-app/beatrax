<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Exceptions\OpenBankingCredentialsException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
class OpenBankingSecretsRepository
{
    // Relative to the storage/app root that UserDataPathService::appPath()
    // resolves (respecting NATIVEPHP_STORAGE_PATH) — no leading `app/`.
    private const PATH_RELATIVE = 'secrets/open-banking.json';

    private const DIR_MODE = 0700;

    private const FILE_MODE = 0600;

    public function __construct(
        private readonly Filesystem $files,
        private readonly SecretShield $shield,
        // Second at-rest layer beyond the shield: the Laravel encrypter
        // (APP_KEY) so the private key + session id stay ciphertext even
        // where the bound shield is the identity PassthroughSecretShield.
        private readonly Encrypter $encrypter,
        // Injected (not the Log facade — larastan strict rules forbid
        // facades here) so the single-user guard can warn. Defaults to
        // NullLogger so unit construction can omit it.
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

    // Gated only on the private key being present, unlike hasApplication()
    // which gates on both — the onboarding wizard writes the private key
    // first and the application_id afterwards, and must be able to load()
    // the just-generated private key to merge the pasted id into it.
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

    // The non-null companion to load(), for call sites that cannot proceed
    // without persisted credentials (the EB HTTP boundary signs every request
    // with them). Fabricating the DTO only here keeps this repository the
    // single credential source the arch guard checks for.
    public function loadOrThrow(): OpenBankingCredentials
    {
        $credentials = $this->load();
        if ($credentials === null) {
            throw OpenBankingCredentialsException::notConfigured();
        }

        return $credentials;
    }

    // A missing file is treated as already-cleared, so this action is
    // idempotent across repeated disconnect calls.
    public function clear(): void
    {
        $absolute = $this->absolutePath();
        if ($this->files->exists($absolute)) {
            $this->files->delete($absolute);
        }
    }

    // Extracted so tests can simulate a rename failure without touching the
    // filesystem destructively; the caller treats it identically to a
    // native rename() returning false.
    protected function performRename(string $tmp, string $final): bool
    {
        return @rename($tmp, $final);
    }

    /**
     * @see performRename()
     */
    protected function userCount(): ?int
    {
        try {
            return User::count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @link ../../../../.docs/features/open-banking/architecture.md
     */
    private function guardSingleUser(): void
    {
        $count = $this->userCount();
        if ($count !== null && $count > 1) {
            $this->logger->warning(
                'OpenBankingSecretsRepository: writing the single global secrets file while '
                .'more than one user account exists. This store has no per-user isolation '
                .'yet (WR-08, SINGLE-USER v1) — per-user or per-connection secret keying is '
                .'required before a second user can safely use open banking.'
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

        // Undo the write layers outer-to-inner: reveal the shield (on the
        // desktop bundle $raw is safeStorage ciphertext, not JSON), then
        // decrypt the APP_KEY layer back to the JSON bytes.
        $revealed = $this->shield->reveal($raw);
        $json = $this->decryptAtRest($revealed);

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw OpenBankingCredentialsException::unreadable($absolute, $e);
        }
        if (! is_array($decoded)) {
            return [];
        }

        // Narrow array<mixed, mixed> -> array<string, mixed>: the
        // top-level shape is a JSON object so every key is a string,
        // but PHPStan's strict mode can't infer that from json_decode.
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    // Legacy fallback: a file written before the APP_KEY layer existed
    // holds shielded-but-unencrypted JSON, for which decrypt() raises
    // DecryptException — treat those revealed bytes as plaintext so the
    // connection survives, and the next save() re-persists them encrypted.
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

        // Two at-rest layers applied inner-to-outer before the bytes touch
        // disk: the APP_KEY encrypter first (ciphertext even where the shield
        // is identity), then the shield — safeStorage ciphertext on the
        // desktop bundle, identity on web/mobile/docker.
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
        // chmod 0700 is applied ONLY on first create. Subsequent writes do
        // NOT re-chmod the directory because that would silently narrow back
        // any permissions an admin widened on purpose (e.g. for a backup tool
        // that needs read access).
        if (is_dir($dir)) {
            return;
        }
        if (! @mkdir($dir, self::DIR_MODE, recursive: true) && ! is_dir($dir)) {
            throw new SecretsWriteFailed(
                "OpenBankingSecretsRepository: could not create parent directory {$dir}."
            );
        }
        if (! @chmod($dir, self::DIR_MODE)) {
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
                "OpenBankingSecretsRepository: failed to encode payload for {$absolute} ({$e->getMessage()})."
            );
        }
    }

    private function writeTempFile(string $tmp, string $bytes): void
    {
        // Narrow umask before opening the temp file so it is born 0600 —
        // otherwise fopen's default mode leaves it world-readable for the
        // brief window before the explicit chmod below runs.
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

            if (! @chmod($tmp, self::FILE_MODE)) {
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
                "OpenBankingSecretsRepository: unexpected failure writing {$tmp}."
            );
        } finally {
            // Always restore the prior umask so the narrowed value does not
            // leak into subsequent writes elsewhere in the request lifecycle.
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
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
