<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Listeners;

use Illuminate\Contracts\Container\Container;
use Modules\Auth\Public\Events\AppLockUnlocked;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Ledger\Internal\Enums\BackfillPass;
use Modules\Ledger\Internal\Services\BackfillCompletionMarkers;
use Modules\Ledger\Internal\Services\StripAsnDescriptionDelimiters;
use Psr\Log\LoggerInterface;
use Throwable;

// A migration runs whenever the schema does, which on a sealed install is
// never a moment the app-lock key is held — so the pass it drove skipped every
// row it existed to convert and recorded itself as Ran. An unlock is the one
// moment the key is reachable, and it is where the sweep is retried until done.
/**
 * @link ../../../../.docs/features/ingestion/asn-description-delimiters.md#the-backfill
 */
final readonly class SweepAsnDelimitersOnUnlock
{
    // The sweep is resolved on demand, never injected: it pulls the codec, the
    // encryption state reader and the search index writer into the container,
    // and an unlock on an install with nothing left to convert should not build
    // any of them.
    public function __construct(
        private Container $container,
        private CurrentUser $currentUser,
        private BackfillCompletionMarkers $markers,
        private LoggerInterface $log,
    ) {}

    public function handle(AppLockUnlocked $event): void
    {
        // An enclave recovery and the test harness both unlock a session no
        // guard has a user bound to; asking for one would throw out of an
        // already-completed unlock.
        if (! $this->currentUser->isAuthenticated()) {
            return;
        }

        try {
            $userId = $this->currentUser->id();

            if ($this->markers->isComplete($userId, BackfillPass::AsnDescriptionDelimiters)) {
                return;
            }

            $this->container->make(StripAsnDescriptionDelimiters::class)
                ->sweepPendingFor($userId, $event->session);
        } catch (Throwable $e) {
            // Never-throw: the unlock has already happened, and a backfill that
            // could not finish must not become an exception on the lock screen
            // the reader just cleared. The marker stays unwritten, so the next
            // unlock retries.
            $this->log->warning('SweepAsnDelimitersOnUnlock: the backfill pass did not complete.', [
                ...SafeExceptionContext::describe($e),
            ]);
        }
    }
}
