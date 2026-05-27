<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Counterparties\Public\Dto\CounterpartyResolutionDto;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Public Counterparties contract — the canonical entry point any
 * cross-module consumer (ImportPipeline, Ledger queries, Recurring
 * detection, Chains resolution, Categorization rules) uses to map a
 * raw CanonicalTransaction onto its counterparty identity.
 *
 * The default implementation is bound in CounterpartiesServiceProvider
 * and walks a seven-step precedence chain (self-account check,
 * known-counterparty-IBAN bridge, merchant resolution, personal-IBAN
 * heuristic, government keyword, bank-fee keyword, unresolved); the
 * chain's full ordering and per-step contract is documented on the
 * default implementation.
 *
 * Implementations MUST be side-effect-free on failure — the import
 * pipeline cannot abort because a resolver lookup threw — and MUST
 * carry an explicit `where('user_id', $user->id)` filter on every
 * read of the `counterparties` table. The BelongsToUser global scope
 * on the `Counterparty` model is a secondary guard that does not fire
 * inside the queue / console contexts where the resolver runs.
 *
 * Returns null when no resolution is possible at all (e.g. the input
 * transaction carries neither a counterparty IBAN nor a description
 * — pathological data the writer layer still records as raw rows).
 */
interface CounterpartyResolver
{
    public function resolve(CanonicalTransaction $tx, User $user): ?CounterpartyResolutionDto;
}
