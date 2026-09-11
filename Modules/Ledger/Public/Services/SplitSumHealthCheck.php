<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
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

        // Grouped by currency as well as transaction, because minor units of
        // two currencies added together are not a figure. A leg group in a
        // currency its transaction is not denominated in cannot add up to it,
        // which is why the currency is compared rather than filtered on.
        $legs = $connection->table('transaction_splits')
            ->selectRaw('transaction_id, settled_currency, sum(settled_amount_minor) as leg_total')
            ->groupBy('transaction_id', 'settled_currency');

        $rows = $connection->table('transactions')
            ->joinSub($legs, 'legs', static fn (JoinClause $join): JoinClause => $join->on('legs.transaction_id', '=', 'transactions.id'))
            ->orderBy('transactions.id')
            ->get([
                'transactions.id',
                'transactions.settled_amount_minor',
                'transactions.settled_currency',
                'legs.settled_currency as leg_currency',
                'legs.leg_total',
            ]);

        /** @var array<int, bool> $balanced */
        $balanced = [];

        foreach ($rows as $row) {
            $id = self::toInt($row->id ?? null);

            $agrees = self::toString($row->leg_currency ?? null) === self::toString($row->settled_currency ?? null)
                && self::toInt($row->leg_total ?? null) === self::toInt($row->settled_amount_minor ?? null);

            // A second group for one transaction is a second currency, so the
            // first group's agreement no longer settles it.
            $balanced[$id] = $agrees && ! array_key_exists($id, $balanced);
        }

        $ids = [];
        foreach ($balanced as $id => $agrees) {
            if ($agrees) {
                continue;
            }

            $ids[] = $id;

            if (count($ids) >= self::REPORT_AT_MOST) {
                break;
            }
        }

        return [count($balanced), $ids];
    }
}
