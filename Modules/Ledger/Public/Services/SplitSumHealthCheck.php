<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Support\SplitLegs;
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
    private const int NAME_AT_MOST = 10;

    // A split whose legs do not add up, asked of the same seam the two
    // category roll-ups ask, so this probe and the surfaces it reports on can
    // never disagree about which rows are broken.
    /**
     * @return literal-string
     */
    private static function legsDisagree(): string
    {
        return 'EXISTS (SELECT 1 FROM transaction_splits AS leg WHERE leg.transaction_id = transactions.id)'
            .' AND NOT '.SplitLegs::addUpToTheParent('transactions.');
    }

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
            [$checked, $disagreeing, $ids] = $this->unbalanced();
        } catch (Throwable) {
            return ['severity' => 'warning', 'message' => 'could not be read — run php artisan migrate'];
        }

        if ($disagreeing === 0) {
            return [
                'severity' => 'ok',
                'message' => $checked === 0
                    ? 'no transaction is split'
                    : sprintf('%s split, each adding up to its transaction', $checked),
            ];
        }

        // A warning rather than a blocker: both rollups fail safe to the
        // parent's own category when the legs disagree, so no total is wrong.
        // What it costs is the split itself -- the chosen leg categories stop
        // counting, and the editor refuses to save until someone rebalances.
        $rest = $disagreeing - count($ids);
        $shown = implode(', ', $ids).($rest > 0 ? sprintf(' (+%d more)', $rest) : '');

        return [
            'severity' => 'warning',
            'message' => $disagreeing.sprintf(" of %s no longer add up to their transaction — their leg categories stopped counting and the spend fell back to the parent's (ids %s); re-open each split to rebalance it", $checked, $shown),
        ];
    }

    // Counted over the whole table and named from a bounded slice of it, which
    // are two statements because they are two questions: a numerator taken from
    // the capped id list reported the cap back as the size of the problem.
    /**
     * @return array{0: int, 1: int, 2: list<int>}
     */
    private function unbalanced(): array
    {
        $connection = $this->db->connection();

        $checked = $connection->table('transaction_splits')->distinct()->count('transaction_id');

        if ($checked === 0) {
            return [0, 0, []];
        }

        $disagreeing = $connection->table('transactions')
            ->whereRaw(self::legsDisagree())
            ->count();

        $rows = $connection->table('transactions')
            ->whereRaw(self::legsDisagree())
            ->orderBy('id')
            ->limit(self::NAME_AT_MOST)
            ->get(['id']);

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = self::toInt($row->id ?? null);
        }

        return [$checked, $disagreeing, $ids];
    }
}
