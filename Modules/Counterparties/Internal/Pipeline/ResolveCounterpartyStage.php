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
        if ($dto === null) {
            return $tx;
        }

        if ($dto->counterpartyId === null) {
            return $tx;
        }

        return $tx->withCounterpartyId($dto->counterpartyId);
    }
}
