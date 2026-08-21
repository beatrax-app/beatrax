<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Contracts\RecordsStatementSummary;
use Modules\Ledger\Public\Dto\StatementSummaryData;

// CSV imports never reach this writer: their adapter returns null from
// SourceAdapter::statementMetadata().
final class StatementSummaryWriter implements RecordsStatementSummary
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(User $user, StatementSummaryData $data): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $extras = $data->extras === null
            ? null
            : json_encode($data->extras, JSON_THROW_ON_ERROR);

        $this->db->connection()
            ->table('statement_summaries')
            ->upsert(
                [[
                    'user_id' => $user->id,
                    'import_run_id' => $data->importRunId,
                    'account_id' => $data->accountId,
                    'iban_owner' => $data->ibanOwner,
                    'statement_number' => $data->statementNumber,
                    'period_start' => $data->periodStart?->toDateTimeString(),
                    'period_end' => $data->periodEnd?->toDateTimeString(),
                    'opening_balance_minor' => $data->openingBalanceMinor,
                    'opening_balance_currency' => $data->openingBalanceCurrency,
                    'opening_balance_date' => $data->openingBalanceDate?->toDateTimeString(),
                    'closing_balance_minor' => $data->closingBalanceMinor,
                    'closing_balance_currency' => $data->closingBalanceCurrency,
                    'closing_balance_date' => $data->closingBalanceDate?->toDateTimeString(),
                    'entry_count' => $data->entryCount,
                    'extras' => $extras,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                uniqueBy: ['user_id', 'import_run_id'],
                update: [
                    'account_id',
                    'iban_owner',
                    'statement_number',
                    'period_start',
                    'period_end',
                    'opening_balance_minor',
                    'opening_balance_currency',
                    'opening_balance_date',
                    'closing_balance_minor',
                    'closing_balance_currency',
                    'closing_balance_date',
                    'entry_count',
                    'extras',
                    'updated_at',
                ],
            );
    }
}
