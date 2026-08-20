<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Pipeline;

use Modules\Core\Models\User;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// Sits between ApplyAutoCategoryStage::apply() and the post-commit
// FingerprintStage::classify() boundary inside ImportPipeline::preview().
// A null resolution or a self_account DTO (counterpartyId null) leaves
// the transaction unchanged; otherwise the FK is stamped via withCounterpartyId().
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
