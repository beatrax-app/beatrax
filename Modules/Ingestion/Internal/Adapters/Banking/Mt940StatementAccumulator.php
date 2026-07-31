<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Modules\Ingestion\Internal\Adapters\Banking\Dto\Mt940BalanceTuple;
use Modules\Ingestion\Internal\Adapters\Banking\Dto\Mt940StatementLine;
use Modules\Ledger\Public\Dto\StatementSummaryData;

/**
 * @link ../../../../../.docs/features/ingestion/architecture.md
 */
final class Mt940StatementAccumulator
{
    public ?string $statementId = null;

    public ?string $ownIban = null;

    public ?string $statementNumber = null;

    public ?Mt940BalanceTuple $openingBalance = null;

    public ?Mt940BalanceTuple $closingBalance = null;

    public ?string $currency = null;

    // Once the first statement's closing balance is seen, later statements
    // in a multi-statement file no longer overwrite header/balance fields;
    // only their :61:/:86: entries keep streaming.
    public bool $firstStatementFrozen = false;

    public bool $multiStatement = false;

    public int $entryCount = 0;

    public int $rowIndex = 0;

    public ?Mt940StatementLine $pendingTag61 = null;

    public function toMetadata(): ?StatementSummaryData
    {
        if ($this->statementId === null || $this->ownIban === null) {
            return null;
        }

        $extras = ['statementId' => $this->statementId];
        if ($this->multiStatement) {
            $extras['multiStatement'] = true;
        }

        return new StatementSummaryData(
            importRunId: 0,
            accountId: 0,
            ibanOwner: $this->ownIban,
            statementNumber: $this->statementNumber,
            periodStart: $this->openingBalance?->date,
            periodEnd: $this->closingBalance?->date,
            openingBalanceMinor: $this->openingBalance?->minor,
            openingBalanceCurrency: $this->openingBalance?->currency,
            openingBalanceDate: $this->openingBalance?->date,
            closingBalanceMinor: $this->closingBalance?->minor,
            closingBalanceCurrency: $this->closingBalance?->currency,
            closingBalanceDate: $this->closingBalance?->date,
            entryCount: $this->entryCount,
            extras: $extras,
        );
    }
}
