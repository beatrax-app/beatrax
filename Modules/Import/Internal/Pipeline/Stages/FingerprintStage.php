<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

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
 */
final class FingerprintStage
{
    public function __construct(private readonly FingerprintComposer $fingerprints) {}

    public function isExistingFingerprint(CanonicalTransaction $tx): bool
    {
        $fingerprint = $this->fingerprints->compose($tx);

        return Transaction::query()->where('fingerprint', $fingerprint)->first() !== null;
    }
}
