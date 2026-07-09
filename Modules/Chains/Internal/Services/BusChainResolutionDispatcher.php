<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Services;

use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;

/**
 * Concrete implementation of the `DispatchesChainResolution` contract.
 * Wraps `ResolveChainLinksJob::dispatchSync($userId)` (the
 * `Dispatchable` trait's static helper) so the concrete job class
 * stays inside `Modules\Chains\Internal\Jobs\` (which
 * `App\PhpStan\Rules\BoundaryRule` forbids other modules from
 * importing directly).
 *
 * Runs via `dispatchSync` (14.1-04, CRYPT-01): the resolvers this job
 * runs (`RetypeByAliasResolver`, `PaypalFundingResolver`, etc.) match
 * against encrypted `counterparty_iban`/`counterparty_name` columns,
 * and the decryption KEK is only reachable through the live, unlocked
 * Session on the calling request. Every call site of this dispatcher
 * is an unlocked HTTP/Livewire request (`ConfirmImport`,
 * `FirstImportStep`) — none is a daemon/scheduler origin (confirmed
 * via `14.1-AUDIT.md`'s dispatch-origin table). Queuing this job would
 * hand it to the KEK-less `queue:work` daemon, which decrypts to empty
 * and silently resolves nothing (RESEARCH Pitfall 1). `dispatchSync`
 * keeps the KEK in-process — never serialized onto the `jobs` table —
 * and, as a consequence, bypasses the queue layer entirely: the
 * `ShouldBeUniqueUntilProcessing` lock on `ResolveChainLinksJob` is
 * only enforced by `PendingDispatch::shouldDispatch()`, which
 * `dispatchSync` never invokes. A same-user double-click now runs the
 * resolver pass twice in sequence instead of collapsing into one
 * queued pass; both passes are idempotent (chain resolution is
 * re-run-safe) so this is a redundant-but-harmless cost, not a
 * correctness regression.
 *
 * Bound as a singleton in `ChainsServiceProvider::register()`.
 *
 * @internal Bound to the public contract — call sites inject the
 *           contract, never this class directly.
 */
final class BusChainResolutionDispatcher implements DispatchesChainResolution
{
    public function dispatchForUser(int $userId): void
    {
        ResolveChainLinksJob::dispatchSync($userId);
    }
}
