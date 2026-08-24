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

final class PinVerificationService
{
    private const BACKOFF_THRESHOLD = 5;

    // Total failures before a permanent sign-out. Public so the lock screen's
    // attempts-remaining copy reads it rather than duplicating the number.
    public const HARD_CAP = 10;

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
    private const BACKOFF_SECONDS = [
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
    /** @var list<array{userId: int, kind: string, severity: string, message: string}> */
    private array $pendingAlerts = [];

    public function verify(int $userId, string $pin, Session $session): ?string
    {
        /** @var string|null $result */
        $result = $this->db->connection()->transaction(
            function () use ($userId, $pin, $session): ?string {
                // Cleared per attempt, not per call: a rolled-back attempt's
                // alerts describe a lockout that was undone with it.
                $this->pendingAlerts = [];

                return $this->verifyWithinTransaction($userId, $pin, $session);
            },
            self::CONTENDED_WRITE_ATTEMPTS,
        );

        $this->flushPendingAlerts();

        return $result;
    }

    private function verifyWithinTransaction(int $userId, string $pin, Session $session): ?string
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if ($row === null || $this->inBackoffWindow($row)) {
            return null;
        }

        if (! is_string($row->pin_hash) || ! $this->pinHasher->verify($pin, $row->pin_hash)) {
            $this->handleFailure($userId, $this->currentFailedAttempts($row));

            return null;
        }

        $dataKey = $this->unwrapDataKey($userId, $pin, $row);
        if ($dataKey !== null) {
            $this->markUnlocked($userId, $session, $dataKey);
        }

        return $dataKey;
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

    private function unwrapDataKey(int $userId, string $pin, \stdClass $row): ?string
    {
        if (! is_string($row->kdf_salt) || ! is_string($row->pin_wrapped_key)) {
            $this->emitAlert($userId, 'auth.lock.corrupted_key', 'critical', 'PIN wrap key blob is missing or not a string.');

            return null;
        }

        $wrapKey = $this->kdf->deriveWrapKey($pin, $row->kdf_salt);
        $dataKey = $this->keyWrap->unwrap($row->pin_wrapped_key, $wrapKey);
        sodium_memzero($wrapKey);

        if ($dataKey === false) {
            $this->emitAlert($userId, 'auth.lock.corrupted_key', 'critical', 'PIN-wrapped key unwrap failed (corrupted blob or wrong key).');

            return null;
        }

        return $dataKey;
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

            $this->emitAlert($userId, 'auth.lock.hard_cap_reached', 'critical', 'Hard cap of '.self::HARD_CAP.' PIN failures reached; session signed out.');
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
            $this->alerts->raiseForUser($alert['userId'], $alert['kind'], $alert['severity'], $alert['message']);
        }
    }

    private function emitAlert(int $userId, string $kind, string $severity, string $message): void
    {
        $this->pendingAlerts[] = [
            'userId' => $userId,
            'kind' => $kind,
            'severity' => $severity,
            'message' => $message,
        ];
    }
}
