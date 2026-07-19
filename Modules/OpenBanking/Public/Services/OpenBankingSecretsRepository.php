<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use RuntimeException;
use Throwable;

/**
 * The ONE place in the OpenBanking module allowed to touch the
 * filesystem secrets path (D-07 arch guard — enforced by
 * Modules\OpenBanking\tests\Contracts\OpenBankingSecretsFileGuardTest).
 *
 * Persists the caller's own Enable Banking "application" credentials
 * (BYO-key, D-05) — application_id, RSA private-key PEM, session/
 * consent tokens, consent expiry, and the resolved bank SCA host — to
 * a chmod-600 JSON file at storage/app/secrets/open-banking.json.
 * NEVER a DB column: any secret in SQLite would leak into DB backups.
 *
 * This is a verbatim port of the git-recovered pre-Phase-12
 * `Modules\EmailScan\Public\Services\OAuthSecretsRepository`
 * (`git show 97decba2^:...`) — the debugged file-backed atomic-write
 * blueprint (WR-04 umask narrowing, WR-08 chmod-parent-only-on-first-
 * create) — layered with D-06's SecretShield wrapping on top: the
 * encoded JSON bytes are run through `SecretShield::protect()` before
 * `fwrite()` and `SecretShield::reveal()`d after `file_get_contents()`.
 * On web/desktop-without-keychain this is identity (`PassthroughSecretShield`);
 * on the desktop bundle it layers OS-keychain-bound ciphertext
 * (`SafeStorageSecretShield`) under the chmod-0600 file permission.
 *
 * Writes are atomic via the tmp+fsync+rename sequence: open a
 * sibling `.tmp` file, write the shielded payload, fflush, fsync
 * (where available), chmod to 0600, then rename over the canonical
 * path. A POSIX rename is atomic, so a crash mid-write leaves the
 * prior file content intact and never produces a half-written file
 * the next reader would choke on. The performRename hook is extracted
 * so tests can simulate a failed rename without touching the
 * filesystem in destructive ways.
 *
 * Failures emit a typed SecretsWriteFailed exception whose message
 * never contains the JSON payload — only the absolute path and a
 * generic description — so any logging surface above this class can
 * never accidentally leak credential material.
 */
class OpenBankingSecretsRepository
{
    private const PATH_RELATIVE = 'app/secrets/open-banking.json';

    private const DIR_MODE = 0700;

    private const FILE_MODE = 0600;

    public function __construct(
        private readonly Filesystem $files,
        private readonly SecretShield $shield,
    ) {}

    /**
     * Wizard credential-gate check: true once an application_id +
     * private-key PEM have been saved, regardless of whether the
     * consent dance has completed yet.
     */
    public function hasApplication(): bool
    {
        $data = $this->readAll();

        return self::stringOrNull($data['application_id'] ?? null) !== null
            && self::stringOrNull($data['private_key_pem'] ?? null) !== null;
    }

    public function save(OpenBankingCredentials $credentials): void
    {
        $this->writeAtomic([
            'application_id' => $credentials->applicationId,
            'private_key_pem' => $credentials->privateKeyPem,
            'session_id' => $credentials->sessionId,
            'consent_expires_at' => $credentials->consentExpiresAt?->toAtomString(),
            'bank_sca_host' => $credentials->bankScaHost,
            'institution_id' => $credentials->institutionId,
        ]);
    }

    /**
     * Gated ONLY on the private key being present. `application_id` is
     * intentionally NOT required here (unlike `hasApplication()`, which
     * gates on both): the onboarding wizard (19-06) writes the private
     * key first (Step 1) and the application_id afterwards (Step 3), and
     * Step 3 must be able to `load()` the just-generated private key to
     * merge the pasted application_id into it. Any caller that needs the
     * "fully registered" signal uses `hasApplication()`, not a null
     * check on `load()`.
     */
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

    /**
     * Disconnect: remove the on-disk credential file entirely. A
     * missing file is treated as already-cleared (idempotent).
     */
    public function clear(): void
    {
        $absolute = $this->absolutePath();
        if ($this->files->exists($absolute)) {
            $this->files->delete($absolute);
        }
    }

    /**
     * Hook extracted so tests can simulate a rename failure without
     * touching the filesystem in destructive ways. Override in a test
     * subclass to return false; the caller treats the failure
     * identically to a native rename() returning false.
     */
    protected function performRename(string $tmp, string $final): bool
    {
        return @rename($tmp, $final);
    }

    /**
     * @return array<string, mixed>
     */
    private function readAll(): array
    {
        $absolute = $this->absolutePath();
        if (! $this->files->exists($absolute)) {
            return [];
        }
        $raw = $this->files->get($absolute);
        if ($raw === '') {
            return [];
        }

        // Reveal BEFORE decode: on the desktop bundle $raw is
        // safeStorage-shielded ciphertext, not JSON.
        $revealed = $this->shield->reveal($raw);

        try {
            $decoded = json_decode($revealed, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // Never include $revealed/$raw in the message — it would
            // leak credential material into any logging surface above.
            throw new RuntimeException(
                "OpenBankingSecretsRepository: failed to parse {$absolute} ({$e->getMessage()})."
            );
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeAtomic(array $data): void
    {
        $absolute = $this->absolutePath();
        $dir = dirname($absolute);
        // chmod 0700 is applied ONLY on first create. Subsequent
        // writes do NOT re-chmod the directory because that would
        // silently narrow back any permissions an admin widened on
        // purpose (e.g. for a backup tool that needs read access).
        if (! is_dir($dir)) {
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

        try {
            $encoded = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new SecretsWriteFailed(
                "OpenBankingSecretsRepository: failed to encode payload for {$absolute} ({$e->getMessage()})."
            );
        }

        // D-06: shield the encoded JSON bytes before they ever touch
        // disk. Identity on web/desktop-without-keychain; safeStorage
        // ciphertext on the desktop bundle.
        $bytes = $this->shield->protect($encoded);

        $tmp = $absolute.'.tmp';

        // Narrow umask BEFORE opening the temp file so the file is
        // born with mode 0600 (0666 & ~0077 = 0600). Without this,
        // fopen's default 0666 & ~0022 = 0644 leaves the temp file
        // world-readable for the brief window between fwrite and the
        // explicit chmod below — a cohabiting OS user racing a `cat`
        // could observe the shielded bytes (or, on web, the raw
        // credential JSON). The explicit chmod is kept as
        // belt-and-braces against umask churn from other libs running
        // in the same request.
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

            if (! $this->performRename($tmp, $absolute)) {
                throw new SecretsWriteFailed(
                    "OpenBankingSecretsRepository: atomic rename failed from {$tmp} to {$absolute}."
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
                "OpenBankingSecretsRepository: unexpected failure writing {$absolute}."
            );
        } finally {
            // Always restore the prior umask so the narrowed value
            // does not leak into subsequent writes elsewhere in the
            // request lifecycle.
            umask($prevUmask);
        }
    }

    private function absolutePath(): string
    {
        return storage_path(self::PATH_RELATIVE);
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
