<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Public\Actions\LogoutAction;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;

final class PinVerificationService
{
    private const int BACKOFF_THRESHOLD = 5;

    // Total failures before a permanent sign-out. Public so the lock screen's
    // attempts-remaining copy reads it rather than duplicating the number.
    public const int HARD_CAP = 10;

    // What the reader can act on. Both branches that raise this kind mean the
    // same thing to them -- the PIN cannot open the lock on this device -- and
    // which blob failed is a log's question, so it rides in metadata.
    private const string CORRUPTED_KEY_LINE = 'core::alerts.messages.auth_lock_corrupted_key';

    private const string UNUSABLE_BLOB_DETAIL = 'PIN wrap key blob is missing, not a string, or carries an unusable salt.';

    private const string UNWRAP_FAILED_DETAIL = 'PIN-wrapped key unwrap failed (corrupted blob or wrong key).';

    // The unlock reads the row and then writes it, and the desktop runs four
    // processes against one SQLite file. A commit landing in between refuses
    // this write outright rather than making it wait, which busy_timeout
    // cannot cover; the read has to be taken again.
    private const int CONTENDED_WRITE_ATTEMPTS = 3;

    // Seconds, indexed by 0-based threshold breach: 30s at BACKOFF_THRESHOLD,
    // doubling to a 300s ceiling.
    /**
     * @var array<int, int>
     */
    private const array BACKOFF_SECONDS = [
        0 => 30,
        1 => 60,
        2 => 300,
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PinHasher $pinHasher,
        private readonly AppLockKdf $kdf,
        private readonly AppLockKeyWrap $keyWrap,
        private readonly LockStateManager $lockState,
        private readonly Clock $clock,
        private readonly LogoutAction $logout,
        private readonly BiometricDeviceStore $biometricStore,
        private readonly SystemAlertWriter $alerts,
    ) {}

    // Raised only after commit: these rows travel to the paired device, and
    // one written inside a rolled-back transaction describes a lockout that
    // never happened.
    /** @var list<array{userId: int, kind: string, severity: string, message: string, metadata: array<string, mixed>}> */
    private array $pendingAlerts = [];

    public function verify(int $userId, string $pin, Session $session): ?string
    {
        $read = $this->configRow($userId);

        // The backoff window short-circuits before any derivation, which is
        // what makes a locked-out attempt cheap rather than the most expensive
        // request the app serves.
        if ($read === null || $this->inBackoffWindow($read)) {
            return null;
        }

        // Derived before the transaction opens. This is seconds of Argon2id on
        // a phone, and holding write intent across it stalls every other
        // process on the file -- and a contended retry would pay it twice.
        $attempt = $this->attemptUnlock($pin, $read);

        /** @var string|null $result */
        $result = $this->db->connection()->transaction(
            function () use ($userId, $session, $read, $attempt): ?string {
                // Cleared per attempt, not per call: a rolled-back attempt's
                // alerts describe a lockout that was undone with it.
                $this->pendingAlerts = [];

                return $this->settle($userId, $session, $read, $attempt);
            },
            self::CONTENDED_WRITE_ATTEMPTS,
        );

        $this->flushPendingAlerts();

        return $result;
    }

    private function configRow(int $userId): ?\stdClass
    {
        return $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first();
    }

    // The unwrap is authenticated encryption, so it already refuses a wrong
    // PIN on its own. The verifier runs only where it failed, to say whether
    // the blob is corrupt or the PIN wrong -- which the unwrap cannot tell
    // apart. The other order paid two derivations for every correct PIN.
    private function attemptUnlock(string $pin, \stdClass $row): PinUnlockAttempt
    {
        $salt = self::wrapSalt($row);
        $wrapped = $row->pin_wrapped_key;

        if ($salt === null || ! is_string($wrapped)) {
            return $this->classifyFailure($pin, $row, self::UNUSABLE_BLOB_DETAIL);
        }

        $wrapKey = $this->kdf->deriveWrapKey($pin, $salt);
        $dataKey = $this->keyWrap->unwrap($wrapped, $wrapKey);
        sodium_memzero($wrapKey);

        return $dataKey === false
            ? $this->classifyFailure($pin, $row, self::UNWRAP_FAILED_DETAIL)
            : PinUnlockAttempt::unlocked($dataKey);
    }

    private function classifyFailure(string $pin, \stdClass $row, string $detail): PinUnlockAttempt
    {
        $hash = $row->pin_hash;

        return is_string($hash) && $this->pinHasher->verify($pin, $hash)
            ? PinUnlockAttempt::corrupted($detail)
            : PinUnlockAttempt::wrongPin();
    }

    // A salt of any other length makes libsodium throw rather than derive, so
    // it is unusable material and not a crash on the way to the alert that
    // already describes it.
    private static function wrapSalt(\stdClass $row): ?string
    {
        $salt = $row->kdf_salt;

        return is_string($salt) && strlen($salt) === SODIUM_CRYPTO_PWHASH_SALTBYTES
            ? $salt
            : null;
    }

    private function settle(int $userId, Session $session, \stdClass $read, PinUnlockAttempt $attempt): ?string
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        // A PIN change that committed while the derivation ran leaves this
        // attempt describing material the row no longer holds, so neither
        // outcome is its to record and the counter is left alone.
        if ($row === null || $this->inBackoffWindow($row) || ! self::sameWrapMaterial($read, $row)) {
            return null;
        }

        return $this->record($userId, $session, $row, $attempt);
    }

    private function record(int $userId, Session $session, \stdClass $row, PinUnlockAttempt $attempt): ?string
    {
        if ($attempt->dataKey !== null) {
            $this->markUnlocked($userId, $session, $attempt->dataKey);

            return $attempt->dataKey;
        }

        if ($attempt->corruptionDetail !== null) {
            $this->emitAlert($userId, 'auth.lock.corrupted_key', 'critical', CopyLine::of(self::CORRUPTED_KEY_LINE), ['detail' => $attempt->corruptionDetail]);

            return null;
        }

        $this->handleFailure($userId, $this->currentFailedAttempts($row));

        return null;
    }

    private static function sameWrapMaterial(\stdClass $read, \stdClass $locked): bool
    {
        return $read->kdf_salt === $locked->kdf_salt
            && $read->pin_wrapped_key === $locked->pin_wrapped_key
            && $read->pin_hash === $locked->pin_hash;
    }

    private function inBackoffWindow(\stdClass $row): bool
    {
        if ($row->locked_until === null) {
            return false;
        }

        $lockedUntilRaw = $row->locked_until;
        if (! is_string($lockedUntilRaw) && ! is_int($lockedUntilRaw)) {
            return false;
        }

        return $this->clock->now() < CarbonImmutable::parse($lockedUntilRaw);
    }

    private function currentFailedAttempts(\stdClass $row): int
    {
        $failedAttempts = $row->failed_attempts;
        if (! is_int($failedAttempts) && ! is_string($failedAttempts)) {
            return 0;
        }

        return (int) $failedAttempts;
    }

    private function markUnlocked(int $userId, Session $session, string $dataKey): void
    {
        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update([
                'failed_attempts' => 0,
                'locked_until' => null,
                'last_activity_at' => $this->clock->now(),
                // Refreshes the cold-start "re-enter PIN every N days" clock
                // read by MobileLockGateway::pinFloorDue().
                'last_pin_unlock_at' => $this->clock->now(),
            ]);

        // The cached config still holds the last_activity_at that caused the
        // lock, so leaving it demands a second PIN on the next request.
        $session->forget(AppLockMiddleware::SESSION_CONFIG_CACHE);

        $this->biometricStore->resetAllForUser($userId);

        $this->lockState->unlock($session, $dataKey);
    }

    // Separates "backoff active" from "wrong PIN", so a correct PIN inside the
    // window is told to wait rather than told it is wrong.
    public function lockedUntil(int $userId): ?CarbonImmutable
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['locked_until']);

        if ($row === null) {
            return null;
        }

        $raw = $row->locked_until;
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $until = CarbonImmutable::parse($raw);

        return $this->clock->now() < $until ? $until : null;
    }

    private function handleFailure(int $userId, int $currentAttempts): void
    {
        $newAttempts = $currentAttempts + 1;

        if ($newAttempts >= self::HARD_CAP) {
            $this->db->connection()
                ->table('user_app_lock_configs')
                ->where('user_id', $userId)
                ->update([
                    'failed_attempts' => $newAttempts,
                    'locked_until' => null,
                ]);

            $this->emitAlert($userId, 'auth.lock.hard_cap_reached', 'critical', CopyLine::of('core::alerts.messages.auth_lock_hard_cap_reached'), ['attempts' => self::HARD_CAP]);
            ($this->logout)();

            return;
        }

        $lockedUntil = null;
        if ($newAttempts >= self::BACKOFF_THRESHOLD) {
            $breachIndex = $newAttempts - self::BACKOFF_THRESHOLD;
            $seconds = self::BACKOFF_SECONDS[min($breachIndex, count(self::BACKOFF_SECONDS) - 1)];
            $lockedUntil = $this->clock->now()->addSeconds($seconds)->toDateTimeString();
        }

        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update([
                'failed_attempts' => $newAttempts,
                'locked_until' => $lockedUntil,
            ]);
    }

    private function flushPendingAlerts(): void
    {
        $raised = $this->pendingAlerts;
        $this->pendingAlerts = [];

        foreach ($raised as $alert) {
            $this->alerts->raiseForUser($alert['userId'], $alert['kind'], $alert['severity'], $alert['message'], $alert['metadata']);
        }
    }

    // The banner renders these by kind, so `message` is the English fallback
    // for a reader on a build whose locale file predates the key. What went
    // wrong technically rides in metadata, where a log can still read it.
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function emitAlert(int $userId, string $kind, string $severity, CopyLine $line, array $metadata = []): void
    {
        $this->pendingAlerts[] = [
            'userId' => $userId,
            'kind' => $kind,
            'severity' => $severity,
            'message' => $line->sentence(),
            'metadata' => StoredCopy::inParams($line) + $metadata,
        ];
    }
}
