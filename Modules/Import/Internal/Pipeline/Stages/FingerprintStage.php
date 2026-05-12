<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;

/**
 * Computes and inspects the SHA-256 fingerprint of a canonical row to mark
 * preview rows as duplicates before they reach the confirm phase.
 *
 * The wizard's preview screen needs to render NEW vs DUPLICATE for each
 * row — that means we must look up the fingerprint in the `transactions`
 * table at preview time rather than relying on the post-confirm
 * `insertOrIgnore` count.
 *
 * The lookup explicitly filters by `user_id` (rather than relying on
 * BelongsToUser's global scope, which falls through to "no scope" in
 * unauthenticated CLI / queue / test contexts) so a fingerprint owned by
 * a different user never marks the current user's preview row as a
 * duplicate.
 */
final class FingerprintStage
{
    public function __construct(private readonly FingerprintComposer $fingerprints) {}

    public function isExistingFingerprint(CanonicalTransaction $tx, User $user): bool
    {
        $fingerprint = $this->fingerprints->compose($tx);

        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->exists();
    }
}
