<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Import\Public\Dto\StartingBalanceCandidate;

/**
 * Strategy interface implemented by every per-source starting-balance
 * detector (ASN CAMT.053, ASN MT940, ICS PDF, PayPal CSV).
 *
 * The `DetectStartingBalancesQuery` aggregator collects every
 * implementation bound under the `starting-balance.detector` container
 * tag and asks each one to inspect a set of previewed import-run ids
 * for a user. Detectors that have no usable opening-balance signal for
 * their source format (PayPal's Saldo column is reset to zero after
 * every funding sweep, for example) return an empty list so the
 * wizard surfaces a manual-entry card per account.
 *
 * Detectors are stateless: they read from the `statement_summaries`
 * and `import_runs` tables but hold no per-call state and are bound
 * as singletons. Side effects (persistence, the wizard's confirm UI)
 * live downstream — detectors only return the candidate list.
 */
interface DetectsStartingBalance
{
    /**
     * Return true when this detector handles the supplied canonical
     * source-format identifier (`camt053`, `mt940`, `ics-pdf`,
     * `paypal-csv`). Used by tests and structured logs to introspect
     * each detector's responsibility; the aggregator itself simply
     * walks every tagged detector and unions their candidate output.
     */
    public function supports(string $sourceFormat): bool;

    /**
     * Scan the supplied import-run ids for usable opening-balance
     * signals for the supplied user. Returns one candidate per
     * detected account (earliest opening-balance date wins inside a
     * single detector). Returns an empty list when the supplied
     * import-run ids carry no rows the detector recognises — the
     * aggregator falls through to the next tagged detector.
     *
     * @param  list<int>  $importRunIds  ImportRun ids of any source format owned by `$user`; the detector filters internally to its own source format.
     * @return list<StartingBalanceCandidate>
     */
    public function detect(array $importRunIds, User $user): array;
}
