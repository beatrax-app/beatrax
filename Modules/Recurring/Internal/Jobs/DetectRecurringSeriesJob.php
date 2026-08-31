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
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\StateMachine\InvalidStateTransitionException;
use Modules\Core\Public\Support\LockStore;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Internal\StateMachines\SeriesRowVanishedException;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Psr\Log\LoggerInterface;

// Dispatched daily from routes/console.php's recurring.detect scheduler
// entry, and on demand from the /recurring re-detect button.

final class DetectRecurringSeriesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

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
     * @param  Session|null  $session  the caller's Session, when one is known (see the
     *                                 class-level architecture doc link). Null on the legacy
     *                                 4-arg test-call shape — treated as "full capability"
     *                                 since those fixtures are never encrypted.
     * @param  AppLockKeyService|null  $appLockKeyService  resolved together with $session
     *                                                     to probe KEK availability; null
     *                                                     has the same legacy-safe meaning.
     * @param  EncryptionMigrationService|null  $encryptionMigrationService  resolved to check
     *                                                                       whether the user has
     *                                                                       encryption enabled at
     *                                                                       all; null has the same
     *                                                                       legacy-safe meaning.
     * @param  LoggerInterface|null  $logger  optional PSR logger for defensive
     *                                        warnings on schema-impossible row shapes and the
     *                                        KEK-absence skip. The Laravel queue worker
     *                                        auto-injects from the container; bare-handle test
     *                                        callers can omit it and the warnings degrade to a
     *                                        silent continue (same behaviour the legacy guard had).
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

        // The shorter test-call shape leaves all three null and gets full
        // capability, which is right for those always-plaintext fixtures.
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
                    // Not called at all, rather than called and left to
                    // silently cluster on undecryptable IBANs.
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
            ->where('state', RecurringSeriesState::Snoozed->value)
            ->where('snoozed_until', '<=', $now)
            ->get();

        foreach ($rows as $row) {
            $id = is_numeric($row->id) ? (int) $row->id : 0;
            if ($id === 0) {
                // The autoincrement PK makes a 0 id structurally impossible, so
                // reaching here is schema corruption and must not stay silent.
                if ($logger !== null) {
                    $logger->warning(
                        'DetectRecurringSeriesJob: encountered non-numeric recurring_series.id during snooze expiry; skipping.',
                        ['user_id' => $user->id, 'row' => (array) $row],
                    );
                }

                continue;
            }
            /** @var RecurringSeries|null $series */
            $series = RecurringSeries::query()->find($id);
            if ($series === null) {
                continue;
            }

            try {
                $stateMachine->transition($series, RecurringSeriesState::Pending->value, 'snooze_expired', 'detector');
            } catch (InvalidStateTransitionException|SeriesRowVanishedException) {
                // A concurrent action moved the row off 'snoozed' or deleted it
                // between the scan and the row lock. dispatchSync bypasses the
                // uniqueness lock, so the loser aborted the whole sweep — and
                // the sibling revival jobs already skip on exactly this.
                continue;
            }
        }
    }
}
