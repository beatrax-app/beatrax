<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Statement-level metadata captured by a source adapter that carries it
 * (CAMT.053, MT940). CSV statements never produce this DTO — the ledger's
 * StatementSummaryWriter is simply not invoked for that path.
 *
 * `importRunId` and `accountId` are populated at the boundary between the
 * adapter (which doesn't know the surrounding import run) and the pipeline
 * (which does). `withImportRunId()` + `withAccountId()` are immutable
 * updates the pipeline applies just before invoking the writer.
 */
final class StatementSummaryData extends Data
{
    /**
     * @param  array<string, mixed>|null  $extras
     */
    public function __construct(
        public readonly int $importRunId,
        public readonly int $accountId,
        public readonly string $ibanOwner,
        public readonly ?string $statementNumber,
        public readonly ?CarbonImmutable $periodStart,
        public readonly ?CarbonImmutable $periodEnd,
        public readonly ?int $openingBalanceMinor,
        public readonly ?string $openingBalanceCurrency,
        public readonly ?CarbonImmutable $openingBalanceDate,
        public readonly ?int $closingBalanceMinor,
        public readonly ?string $closingBalanceCurrency,
        public readonly ?CarbonImmutable $closingBalanceDate,
        public readonly int $entryCount,
        public readonly ?array $extras = null,
    ) {}

    public function withImportRunId(int $importRunId): self
    {
        return new self(
            importRunId: $importRunId,
            accountId: $this->accountId,
            ibanOwner: $this->ibanOwner,
            statementNumber: $this->statementNumber,
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            openingBalanceMinor: $this->openingBalanceMinor,
            openingBalanceCurrency: $this->openingBalanceCurrency,
            openingBalanceDate: $this->openingBalanceDate,
            closingBalanceMinor: $this->closingBalanceMinor,
            closingBalanceCurrency: $this->closingBalanceCurrency,
            closingBalanceDate: $this->closingBalanceDate,
            entryCount: $this->entryCount,
            extras: $this->extras,
        );
    }

    public function withAccountId(int $accountId): self
    {
        return new self(
            importRunId: $this->importRunId,
            accountId: $accountId,
            ibanOwner: $this->ibanOwner,
            statementNumber: $this->statementNumber,
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            openingBalanceMinor: $this->openingBalanceMinor,
            openingBalanceCurrency: $this->openingBalanceCurrency,
            openingBalanceDate: $this->openingBalanceDate,
            closingBalanceMinor: $this->closingBalanceMinor,
            closingBalanceCurrency: $this->closingBalanceCurrency,
            closingBalanceDate: $this->closingBalanceDate,
            entryCount: $this->entryCount,
            extras: $this->extras,
        );
    }
}
