<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Throwable;

// Lives in Public so Core's DoctorCommand reaches it without crossing into
// Ledger Internal, and returns plain values so that command builds the
// ProbeResult. Mirrors FingerprintHealthCheck, which asks the same kind of
// question about the other thing a transaction cannot state twice.
final readonly class SplitSumHealthCheck
{
    use CoercesScalars;

    // The legs are refused locally unless they add up, so this is here for the
    // one path that never asked: a peer's op. A SET raising a leg is not gated,
    // and the parent's own settled_amount_minor merges as plain LWW, so either
    // side of the equation can move on its own.
    private const int REPORT_AT_MOST = 20;

    // The sum names the currency it counts with a where, and a leg group in a
    // currency its transaction is not denominated in is caught beside it
    // rather than added to it: minor units of two currencies are not a figure.
    // Mirrors CategoryAttribution::PARENT_HOLDS_THE_AMOUNT, read the other way.
    private const string LEGS_DISAGREE = <<<'SQL'
        EXISTS (SELECT 1 FROM transaction_splits AS leg WHERE leg.transaction_id = transactions.id)
        AND (
            COALESCE((
                SELECT SUM(leg.settled_amount_minor) FROM transaction_splits AS leg
                 WHERE leg.transaction_id = transactions.id
                   AND leg.settled_currency = transactions.settled_currency
            ), 0) <> transactions.settled_amount_minor
            OR EXISTS (
                SELECT 1 FROM transaction_splits AS leg
                 WHERE leg.transaction_id = transactions.id
                   AND leg.settled_currency <> transactions.settled_currency
            )
        )
        SQL;

    public function __construct(private DatabaseManager $db) {}

    public function label(): string
    {
        return 'split leg totals';
    }

    /**
     * @return 'ok'|'warning'
     */
    public function severity(): string
    {
        return $this->result()['severity'];
    }

    public function message(): string
    {
        return $this->result()['message'];
    }

    /**
     * @return array{severity: 'ok'|'warning', message: string}
     */
    private function result(): array
    {
        try {
            [$checked, $ids] = $this->unbalanced();
        } catch (Throwable) {
            return ['severity' => 'warning', 'message' => 'could not be read — run php artisan migrate'];
        }

        if ($checked === 0) {
            return ['severity' => 'ok', 'message' => 'no transaction is split'];
        }

        if ($ids === []) {
            return ['severity' => 'ok', 'message' => "{$checked} split, each adding up to its transaction"];
        }

        // A warning rather than a blocker: both rollups fail safe to the
        // parent's own category when the legs disagree, so no total is wrong.
        // What it costs is the split itself -- the chosen leg categories stop
        // counting, and the editor refuses to save until someone rebalances.
        $shown = implode(', ', array_slice($ids, 0, 10));
        $more = count($ids) > 10 ? ' and more' : '';

        return [
            'severity' => 'warning',
            'message' => count($ids)." of {$checked} no longer add up to their transaction — their leg categories stopped counting and the spend fell back to the parent's (ids {$shown}{$more}); re-open each split to rebalance it",
        ];
    }

    /**
     * @return array{0: int, 1: list<int>}
     */
    private function unbalanced(): array
    {
        $connection = $this->db->connection();

        $checked = $connection->table('transaction_splits')->distinct()->count('transaction_id');

        if ($checked === 0) {
            return [0, []];
        }

        $rows = $connection->table('transactions')
            ->whereRaw(self::LEGS_DISAGREE)
            ->orderBy('id')
            ->limit(self::REPORT_AT_MOST)
            ->get(['id']);

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = self::toInt($row->id ?? null);
        }

        return [$checked, $ids];
    }
}
