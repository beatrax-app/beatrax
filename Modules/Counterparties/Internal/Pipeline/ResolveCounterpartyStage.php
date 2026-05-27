<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Pipeline;

use Modules\Core\Models\User;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * ImportPipeline stage that resolves a canonical transaction onto its
 * Counterparty FK. Sits between `ApplyAutoCategoryStage::apply()` and
 * the post-commit boundary (`FingerprintStage::classify()`) inside
 * `ImportPipeline::preview()`.
 *
 * Algorithm:
 *
 *   1. Delegate to the injected `CounterpartyResolver` Public contract.
 *      The default implementation walks the seven-step precedence chain
 *      documented on `CounterpartyResolverService`; this stage is the
 *      pipeline glue, not the resolution algorithm.
 *
 *   2. When the resolver returns `null` (pathological row carrying no
 *      name / no IBAN / no description) OR a DTO whose `counterpartyId`
 *      is `null` (the `self_account` branch — the user's own account
 *      legs short-circuit without writing a counterparty row), return
 *      the input transaction unchanged. The downstream FingerprintStage
 *      + RecordTransactions still process the row; the
 *      `transactions.counterparty_id` column simply stays NULL.
 *
 *   3. Otherwise, stamp the resolved FK onto the outgoing canonical
 *      transaction via `withCounterpartyId()`. RecordTransactions
 *      persists the FK as part of the standard `toAttributes()` map.
 *
 * Idempotency: the resolver upserts via `firstOrCreate` keyed on
 * (user_id, slug); calling `run()` twice on the same transaction
 * returns the same counterpartyId without issuing a second INSERT, so
 * a re-import never duplicates counterparty rows.
 *
 * Cross-user safety: the resolver itself filters every read by
 * `$user->id`; this stage performs no DB writes of its own and adds
 * no new cross-user surface.
 *
 * Event emission: the resolver fires `CounterpartyResolved` on every
 * upsert; the stage observes the event indirectly via the Public
 * contract and does not emit its own events.
 */
final class ResolveCounterpartyStage implements ResolvesCounterparties
{
    public function __construct(
        private readonly CounterpartyResolver $resolver,
    ) {}

    public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction
    {
        $dto = $this->resolver->resolve($tx, $user);
        if ($dto === null) {
            return $tx;
        }

        if ($dto->counterpartyId === null) {
            return $tx;
        }

        return $tx->withCounterpartyId($dto->counterpartyId);
    }
}
