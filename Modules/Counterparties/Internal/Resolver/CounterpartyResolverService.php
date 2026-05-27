<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Resolver;

use LogicException;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Dto\CounterpartyResolutionDto;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Default `CounterpartyResolver` implementation — walks the seven-step
 * precedence chain that maps a CanonicalTransaction onto a canonical
 * Counterparty row.
 *
 * Chain ordering (first match wins):
 *
 *   1. Self-account check — the user's own account IBANs
 *      short-circuit to type=`self_account` and DO NOT materialise a
 *      counterparties row (the profile page routes back to the
 *      account view instead).
 *   2. Known-counterparty-IBAN bridge — institution IBANs (PayPal
 *      Luxembourg, ICS at ABN AMRO) resolve to type=`bank` via the
 *      `ResolvesKnownCounterpartyIban` Public contract from the
 *      Import module.
 *   3. Merchant resolution — the description is run through
 *      `MerchantNameResolver` and a hit produces type=`merchant`.
 *   4. Personal-IBAN heuristic — a Dutch IBAN paired with a
 *      personal-looking counterparty name appearing on a
 *      transfer_out / transfer_in row resolves to type=`personal`
 *      with the privacy default: slug uses the display name only and
 *      never carries the IBAN.
 *   5. Government keyword fallback — descriptions containing one of
 *      the canonical agency keywords resolve to type=`government`.
 *   6. Description-keyword bank-fee fallback — descriptions matching
 *      one of the bank-fee patterns resolve to type=`bank` with
 *      metadata.subcategory=`fee`.
 *   7. Unresolved — type=`unknown`, the IBAN is preserved on the
 *      Counterparty row for the Triage queue surface.
 *
 * The class body is filled in by the follow-up task in this plan
 * (the 7-step chain, the slug-collision suffixing, and the per-step
 * upsert). This first revision ships the binding stub so the
 * `CounterpartiesServiceProvider` singleton wiring is exercised
 * end-to-end and the boundary arch invariant catches the class file
 * at its final namespace location.
 */
final class CounterpartyResolverService implements CounterpartyResolver
{
    public function resolve(CanonicalTransaction $tx, User $user): ?CounterpartyResolutionDto
    {
        unset($tx, $user);

        throw new LogicException(
            'CounterpartyResolverService::resolve() is not implemented yet.'
        );
    }
}
