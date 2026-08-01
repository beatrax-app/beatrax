<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Import\Public\Contracts\DetectsStartingBalance;
use Modules\Import\Public\Dto\StartingBalanceCandidate;
use Modules\Ingestion\Public\Enums\SourceFormat;

/**
 * @link ../../../../.docs/features/import/architecture.md#starting-balance-detection
 */
final class Camt053StartingBalanceDetector implements DetectsStartingBalance
{
    use CoercesScalars;

    private const SOURCE_FORMAT = SourceFormat::Camt053->value;

    public function __construct(private readonly DatabaseManager $db) {}

    public function supports(string $sourceFormat): bool
    {
        return $sourceFormat === self::SOURCE_FORMAT;
    }

    public function detect(array $importRunIds, User $user): array
    {
        if ($importRunIds === []) {
            return [];
        }

        $rows = $this->db->connection()
            ->table('statement_summaries')
            ->join('import_runs', 'import_runs.id', '=', 'statement_summaries.import_run_id')
            ->where('statement_summaries.user_id', $user->id)
            ->where('import_runs.user_id', $user->id)
            ->where('import_runs.source_format', self::SOURCE_FORMAT)
            ->whereIn('statement_summaries.import_run_id', $importRunIds)
            ->whereNotNull('statement_summaries.opening_balance_minor')
            ->whereNotNull('statement_summaries.opening_balance_date')
            ->orderBy('statement_summaries.opening_balance_date', 'asc')
            ->orderBy('statement_summaries.id', 'asc')
            ->select([
                'statement_summaries.account_id',
                'statement_summaries.opening_balance_minor',
                'statement_summaries.opening_balance_date',
            ])
            ->get();

        // Earliest opening-balance-date per account wins; multiple CAMT
        // statements covering overlapping periods can report different
        // opening balances on the same date, so the aggregator (not
        // this detector) resolves those cross-source conflicts.
        $earliestDatePerAccount = [];
        $emittedKeys = [];
        $out = [];
        foreach ($rows as $row) {
            $accountId = self::toInt($row->account_id);
            $isoDate = self::dateOnly(self::toString($row->opening_balance_date));

            if (! isset($earliestDatePerAccount[$accountId])) {
                $earliestDatePerAccount[$accountId] = $isoDate;
            } elseif ($isoDate !== $earliestDatePerAccount[$accountId]) {
                // Rows are sorted ASC by date — anything beyond the
                // earliest date for this account is by definition
                // later and not a candidate.
                continue;
            }

            $minor = self::toInt($row->opening_balance_minor);
            $dedupKey = $accountId.'|'.$isoDate.'|'.$minor;
            if (isset($emittedKeys[$dedupKey])) {
                continue;
            }
            $emittedKeys[$dedupKey] = true;

            $out[] = new StartingBalanceCandidate(
                accountId: $accountId,
                openingBalanceMinor: $minor,
                openingBalanceDate: $isoDate,
                sourceFormat: self::SOURCE_FORMAT,
            );
        }

        return $out;
    }

    // Strips the time component so the candidate carries an ISO date
    // ready for the accounts.starting_balance_date `date` write target.
    private static function dateOnly(string $raw): string
    {
        $spacePos = strpos($raw, ' ');
        if ($spacePos === false) {
            return strlen($raw) > 10 ? substr($raw, 0, 10) : $raw;
        }

        return substr($raw, 0, $spacePos);
    }
}
