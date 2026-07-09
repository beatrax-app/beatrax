<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\LockStore;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Psr\Log\LoggerInterface;

/**
 * Per-user recurring-detection sweep. Runs the snooze-expiry pass
 * first (flipping `snoozed` rows back to `pending` once their
 * `snoozed_until` has elapsed) and then iterates every container-
 * tagged `recurring.detector` implementation against the user's
 * detection window.
 *
 * Concurrency contract:
 *  - `ShouldBeUniqueUntilProcessing` keyed on `uniqueId() = userId`
 *    collapses a same-day re-dispatch (scheduled tick or the on-demand
 *    /recurring re-detect button) into a single queued pass; the lock
 *    releases the moment a worker begins `handle()`.
 *  - `tries = 3` + `backoff = [60, 300, 900]` keeps a transient queue
 *    or DB hiccup from final-failing the sweep without the prior two
 *    retries.
 *
 * Queue-uniqueness lock resolution is delegated to the shared
 * `Modules\Core\Public\Support\LockStore` helper: `uniqueVia()`
 * returns `LockStore::forUniqueJobs()`, which resolves the cache store
 * named by `config('cache.locks_store')`.
 *
 * Dispatched daily from `routes/console.php` via the
 * `recurring.detect` scheduler entry, and on demand from the
 * `/recurring` re-detect button. The sweep runs read-mostly against
 * `transactions` and writes only to the Recurring-owned tables —
 * the `noTransactionWritesFromRecurring` arch invariant blocks any
 * cross-module write.
 *
 * **CRYPT-01 (14.1-08, D-06) — two dispatch origins, two KEK
 * postures:** this job has exactly two dispatch origins with
 * DIFFERENT decrypt capability:
 *
 *   - `RecurringPage::reDetect()` dispatches via `dispatchSync`
 *     (14.1-04) — runs fully in-process on the SAME request whose
 *     Session is unlocked, so the KEK is always available.
 *   - `routes/console.php`'s daily `recurring.detect` scheduler
 *     entry dispatches through the real queue — the queue worker
 *     process has never unlocked a Session, so the KEK is NEVER
 *     available there.
 *
 * `IncomeSeriesDetector` clusters on `counterparty_iban`, a
 * `SensitiveFieldRegistry`-listed column: under an encrypted user
 * with no KEK, every row's ciphertext differs (random nonce), so
 * clustering on the raw stored value would scatter every group below
 * the 2-occurrence threshold and silently detect nothing — with NO
 * signal that anything is wrong. `handle()` therefore probes KEK
 * availability (`AppLockKeyService::release()`) AND whether the user
 * has encryption enabled at all (`EncryptionMigrationService::
 * isEnabled()`); when the user IS encrypted and the KEK is ABSENT,
 * the iban-dependent `IncomeSeriesDetector` pass is explicitly
 * SKIPPED (never invoked) and a warning is logged naming the user —
 * `ExpenseSeriesDetector` (which does not depend on `counterparty_iban`
 * for its own clustering) still runs unaffected. A non-encrypted
 * user's sweep is unaffected either way (the codec's decrypt call is
 * a documented no-op pass-through for plaintext).
 *
 * **Known limitation:** until a future phase provides a headless-KEK
 * mechanism, an encrypted user's income-series detection only
 * actually clusters via the in-app `/recurring` "Detect now" button
 * (`dispatchSync`, KEK present) — the daily background sweep skips
 * the iban-dependent pass for that user and logs why, rather than
 * running it and reporting nothing.
 *
 * The new `$session`/`$appLockKeyService`/`$encryptionMigrationService`
 * `handle()` parameters are all nullable with no default requirement
 * on callers — every pre-existing direct `handle(...)` test call
 * (4 positional args) keeps working unchanged: passing none of them
 * defaults `$canDecryptIban` to `true` (full legacy behaviour, correct
 * for the non-encrypted fixtures those tests use, since the codec call
 * would have been a no-op anyway). Production dispatch (both
 * `dispatchSync` and the real queue) always resolves all three via
 * `RecurringServiceProvider`'s `bindMethod` closure — see there for
 * the wiring that makes both real dispatch origins receive their true
 * per-run Session context.
 */
final class DetectRecurringSeriesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    /**
     * @param  iterable<SeriesDetector>  $detectors  container-tagged `recurring.detector`
     * @param  Session|null  $session  the caller's Session, when one is known (see class
     *                                 docblock's CRYPT-01 note). Null on the legacy 4-arg
     *                                 test-call shape — treated as "full capability" since
     *                                 those fixtures are never encrypted.
     * @param  AppLockKeyService|null  $appLockKeyService  resolved together with $session
     *                                                     to probe KEK availability; null
     *                                                     has the same legacy-safe meaning.
     * @param  EncryptionMigrationService|null  $encryptionMigrationService  resolved to check
     *                                                                       whether the user has
     *                                                                       encryption enabled at
     *                                                                       all; null has the same
     *                                                                       legacy-safe meaning.
     * @param  LoggerInterface|null  $logger  optional PSR-3 logger for defensive
     *                                        warnings on schema-impossible row shapes AND the
     *                                        CRYPT-01 KEK-absence skip. The Laravel queue
     *                                        worker auto-injects from the container; bare-handle test
     *                                        callers can omit it and the warnings degrade to a silent
     *                                        continue (same behaviour the legacy guard had).
     */
    public function handle(
        DatabaseManager $db,
        Clock $clock,
        iterable $detectors,
        RecurringSeriesStateMachine $stateMachine,
        ?Session $session = null,
        ?AppLockKeyService $appLockKeyService = null,
        ?EncryptionMigrationService $encryptionMigrationService = null,
        ?LoggerInterface $logger = null,
    ): void {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();

        $this->expireSnoozes($db, $clock, $stateMachine, $logger, $user);

        // CRYPT-01 (14.1-08): only gate on KEK-absence when a real
        // Session/AppLockKeyService/EncryptionMigrationService context was
        // resolved (production dispatch — see class docblock). The legacy
        // 4-arg test-call shape leaves all three null, which defaults to
        // "full capability" — correct for those tests' always-plaintext
        // fixtures.
        $canDecryptIban = true;
        if ($session !== null && $appLockKeyService !== null && $encryptionMigrationService !== null) {
            $hasKek = $appLockKeyService->release($session) !== null;
            $isEncrypted = $encryptionMigrationService->isEnabled($this->userId);
            $canDecryptIban = $hasKek || ! $isEncrypted;

            if (! $canDecryptIban && $logger !== null) {
                $logger->warning(
                    'DetectRecurringSeriesJob: no app-lock KEK available for an encrypted user in this run — income-series (iban-dependent) detection skipped for this sweep; it will run on the next in-app "Detect now" refresh.',
                    ['user_id' => $this->userId],
                );
            }
        }

        foreach ($detectors as $detector) {
            if ($detector instanceof IncomeSeriesDetector) {
                if (! $canDecryptIban) {
                    // Explicit skip — never call the iban-dependent
                    // detector at all, so it never runs and silently
                    // produces an empty/garbage result (RESEARCH
                    // "daemon must skip, never silently fail" mandate).
                    continue;
                }
                $detector->detectForUser($user, $session);

                continue;
            }
            $detector->detectForUser($user);
        }
    }

    private function expireSnoozes(
        DatabaseManager $db,
        Clock $clock,
        RecurringSeriesStateMachine $stateMachine,
        ?LoggerInterface $logger,
        User $user,
    ): void {
        $now = $clock->now()->toDateTimeString();
        $rows = $db->connection()->table('recurring_series')
            ->select(['id'])
            ->where('user_id', $user->id)
            ->where('state', 'snoozed')
            ->where('snoozed_until', '<=', $now)
            ->get();

        foreach ($rows as $row) {
            $id = is_numeric($row->id) ? (int) $row->id : 0;
            if ($id === 0) {
                // The schema's autoincrement PK makes a numeric 0 id
                // structurally impossible — logging this rather than
                // silently continuing turns a schema corruption into a
                // visible warning instead of a no-op.
                if ($logger !== null) {
                    $logger->warning(
                        'DetectRecurringSeriesJob: encountered non-numeric recurring_series.id during snooze expiry; skipping.',
                        ['user_id' => $user->id, 'row' => (array) $row],
                    );
                }

                continue;
            }
            /** @var RecurringSeries $series */
            $series = RecurringSeries::query()->findOrFail($id);
            $stateMachine->transition($series, 'pending', 'snooze_expired', 'detector');
        }
    }
}
