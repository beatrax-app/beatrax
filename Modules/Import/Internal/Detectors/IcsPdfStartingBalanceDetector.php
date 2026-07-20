<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\DetectsStartingBalance;
use Modules\Import\Public\Dto\StartingBalanceCandidate;

/**
 * Starting-balance detector specialised for Mijn ICS consumer-portal
 * PDF statement imports (`source_format = 'ics-pdf'`).
 *
 * Reads the `statement_summaries.opening_balance_minor` +
 * `opening_balance_date` columns the ICS PDF parser writes from the
 * per-statement opening-balance row inside every monthly PDF. When
 * the user has multiple ICS PDF statements for the same card, the
 * earliest `opening_balance_date` per account wins so the candidate
 * carries the genuine first-of-record balance.
 *
 * The query is explicitly user-scoped on both `statement_summaries`
 * and `import_runs` so worker contexts that bypass the
 * `BelongsToUser` Eloquent global scope cannot accidentally leak
 * one user's opening balance into another user's wizard.
 */
final class IcsPdfStartingBalanceDetector implements DetectsStartingBalance
{
    private const SOURCE_FORMAT = 'ics-pdf';

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

        // Earliest opening-balance-date per account wins; the
        // aggregator (not this detector) resolves any cross-source
        // opening-balance conflicts among candidates sharing a date.
        $earliestDatePerAccount = [];
        $emittedKeys = [];
        $out = [];
        foreach ($rows as $row) {
            $accountId = self::toInt($row->account_id);
            $isoDate = self::dateOnly(self::toString($row->opening_balance_date));

            if (! isset($earliestDatePerAccount[$accountId])) {
                $earliestDatePerAccount[$accountId] = $isoDate;
            } elseif ($isoDate !== $earliestDatePerAccount[$accountId]) {
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

    /**
     * Strip the time component from the source `dateTime` column so the
     * candidate carries an ISO date (`YYYY-MM-DD`) ready for the
     * `accounts.starting_balance_date` `date` write target.
     */
    private static function dateOnly(string $raw): string
    {
        $spacePos = strpos($raw, ' ');
        if ($spacePos === false) {
            return strlen($raw) > 10 ? substr($raw, 0, 10) : $raw;
        }

        return substr($raw, 0, $spacePos);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
