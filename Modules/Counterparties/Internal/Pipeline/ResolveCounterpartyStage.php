<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Pipeline;

use Modules\Core\Models\User;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// Runs between ApplyAutoCategoryStage::apply() and the post-commit
// FingerprintStage::classify() boundary inside ImportPipeline::preview().
final readonly class ResolveCounterpartyStage implements ResolvesCounterparties
{
    public function __construct(
        private CounterpartyResolver $resolver,
    ) {}

    public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction
    {
        $dto = $this->resolver->resolve($tx, $user);
        if ($dto === null || $dto->counterpartyId === null) {
            return $tx;
        }

        // A rule the reader wrote names the party outright; this stage reads
        // the row's own text. Running after the rules, an unconditional stamp
        // replaced every one of their answers with the file's. The resolve
        // still runs: its upsert is what lists the merchant either way.
        /** @link ../../../../.docs/features/categorization/rule-evaluation-order.md */
        return $tx->counterpartyId === null
            ? $tx->withCounterpartyId($dto->counterpartyId)
            : $tx;
    }
}
