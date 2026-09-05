<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Services;

use Closure;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\Import\Public\Exceptions\ImportNotConfirmableException;
use Modules\OpenBanking\Internal\Dto\OpenBankingFetchResult;
use Modules\OpenBanking\Internal\Dto\OpenBankingSyncOutcome;
use Modules\OpenBanking\Internal\Enums\SyncAttemptStatus;
use Modules\OpenBanking\Internal\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Internal\Events\OpenBankingImportedNothing;
use Modules\OpenBanking\Internal\Exceptions\EnableBankingApiException;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Throwable;

// The two-timestamp rule, once. A failed attempt must not advance the signal a
// reader takes as "how current is my data", and a silently-failing scheduled
// sync must still be visible as an attempt that ran.
final readonly class OpenBankingSyncRunner
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
        private OpenBankingFetchService $fetchService,
    ) {}

    // The scheduler's mode: nobody is watching, so the fetched rows are
    // confirmed into the ledger in the same attempt.
    public function runAndConfirm(int $connectionId, User $user): OpenBankingSyncOutcome
    {
        return $this->run(
            $connectionId,
            $user,
            fn (): OpenBankingFetchResult => $this->fetchService->fetchAndConfirm($connectionId, $user),
            announceRefusal: true,
        );
    }

    // "Sync now": the reader confirms the preview themselves, so the outcome
    // has to carry it back.
    public function runPreview(int $connectionId, User $user): OpenBankingSyncOutcome
    {
        return $this->run(
            $connectionId,
            $user,
            fn (): OpenBankingFetchResult => $this->fetchService->preview($connectionId, $user),
        );
    }

    /**
     * @param  Closure(): OpenBankingFetchResult  $fetch
     */
    private function run(int $connectionId, User $user, Closure $fetch, bool $announceRefusal = false): OpenBankingSyncOutcome
    {
        // The job's own queue-level uniqueness is released before processing
        // begins, so without re-taking the key here a "Sync now" click and the
        // 06:00 tick would decide last_successful_sync_at by arrival order.
        $lock = LockStore::lockProvider()->lock(
            UniqueLock::getKey(new SyncOpenBankingAccountJob($connectionId)),
            SyncOpenBankingAccountJob::UNIQUE_FOR_SECONDS,
        );

        if ($lock->get() !== true) {
            return OpenBankingSyncOutcome::alreadyRunning();
        }

        try {
            return $this->attempt($connectionId, $user, $fetch, $announceRefusal);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  Closure(): OpenBankingFetchResult  $fetch
     */
    private function attempt(int $connectionId, User $user, Closure $fetch, bool $announceRefusal): OpenBankingSyncOutcome
    {
        $now = $this->clock->now()->toDateTimeString();

        try {
            $result = $fetch();
        } catch (Throwable $e) {
            return $this->recordFailure($connectionId, $user, $now, $e);
        }

        // Only Ok moves the freshness signal. A truncated walk left pages the
        // bank still holds, and a run that filed none of its rows put no money
        // in the ledger; either one advancing it would date a sync that was not.
        $status = $result->attemptStatus();

        $this->recordAttempt($connectionId, $user->id, $now, $status, [
            ...($status === SyncAttemptStatus::Ok ? ['last_successful_sync_at' => $now] : []),
            ...($result->committedThrough === null ? [] : ['fetched_through_at' => $result->committedThrough->toDateTimeString()]),
        ]);

        // Only the unattended run announces. The queue is told nothing — a
        // refused import is not worth a retry — and the connection row lives on
        // a screen the reader has to already suspect something to open.
        if ($announceRefusal && $status === SyncAttemptStatus::NothingImported) {
            $this->events->dispatch(new OpenBankingImportedNothing(
                connectionId: $connectionId,
                userId: $user->id,
                rowsFetched: $result->preview->totalRows(),
            ));
        }

        return OpenBankingSyncOutcome::completed($result);
    }

    private function recordFailure(int $connectionId, User $user, string $now, Throwable $e): OpenBankingSyncOutcome
    {
        $consentFailed = EnableBankingApiException::consentFailureWithin($e);
        $status = $consentFailed ? SyncAttemptStatus::ConsentFailed : SyncAttemptStatus::Error;

        // The refusal is written onto the connection, not just logged as an
        // attempt: without it the tile keeps reading "Connected" off a consent
        // window with months left on a session the bank has already withdrawn.
        $this->recordAttempt($connectionId, $user->id, $now, $status, $consentFailed ? ['consent_revoked_at' => $now] : []);

        if ($consentFailed) {
            $this->events->dispatch(new OpenBankingConsentFailed(
                connectionId: $connectionId,
                userId: $user->id,
                reason: substr($e->getMessage(), 0, 500),
            ));
        }

        // A refusal the next attempt would only repeat is not worth a retry:
        // the window is still unconfirmed, so the same rows come back and are
        // refused again. A fetch that stopped mid-walk is the exception it
        // answers for, and the backoff is what a bank's bad minute needs.
        return OpenBankingSyncOutcome::failed(
            $status,
            $e,
            retryable: ! $e instanceof ImportNotConfirmableException || $e->anotherReadCouldDiffer(),
        );
    }

    // The user_id predicate is not redundant with the id: the row's owner can
    // be cleared by a cascading delete while the fetch is in flight, and a
    // timestamp then describes an attempt made for somebody who is gone.
    /**
     * @param  array<string, string>  $advanced
     */
    private function recordAttempt(
        int $connectionId,
        int $userId,
        string $now,
        SyncAttemptStatus $status,
        array $advanced,
    ): void {
        $this->db->connection()
            ->table('open_banking_connections')
            ->where('id', $connectionId)
            ->where('user_id', $userId)
            ->update($advanced + [
                'last_attempt_at' => $now,
                'last_attempt_status' => $status->value,
                'updated_at' => $now,
            ]);
    }
}
