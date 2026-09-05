<?php

declare(strict_types=1);

namespace Modules\Search\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Search\Internal\Services\SearchIndexRepairQueue;
use Throwable;

// Lives in Public so Core's DoctorCommand can depend on it without
// crossing into Search Internal. Returns plain PHP values (no Core
// Internal types) so DoctorCommand builds its own ProbeResult.
final readonly class FtsHealthCheck
{
    public function __construct(
        private DatabaseManager $db,
        private SearchIndexRepairQueue $repairs,
    ) {}

    public function label(): string
    {
        return 'FTS search index';
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
            $tableCount = $this->db->connection()->table('transactions')->count();
            $indexCount = $this->db->connection()->table('transaction_search_docs')->count();
            $owed = $this->repairs->owedTotal();
        } catch (Throwable) {
            return [
                'severity' => 'warning',
                'message' => 'FTS index table absent — run php artisan migrate then search:reindex',
            ];
        }

        // A row count matching the table says nothing about whether the bodies
        // hold words: a keyless drain leaves the doc in place with its text
        // gone, so the two questions are reported side by side.
        $problems = [];

        $drift = $this->drift($tableCount - $indexCount);
        if ($drift !== null) {
            $problems[] = $drift;
        }

        if ($owed > 0) {
            $problems[] = "{$owed} awaiting a key to rebuild";
        }

        return $problems === []
            ? ['severity' => 'ok', 'message' => "FTS index: {$indexCount} rows — in sync"]
            : ['severity' => 'warning', 'message' => "FTS index: {$indexCount} rows — ".implode(', ', $problems)];
    }

    // An index AHEAD of the table is the normal shape of the damage --
    // orphaned documents a delete never reaped -- and printing it as
    // "-364 behind table" told the reader the opposite of what happened.
    private function drift(int $delta): ?string
    {
        if ($delta === 0) {
            return null;
        }

        return $delta < 0
            ? (-$delta).' ahead of table (orphaned documents)'
            : $delta.' behind table';
    }
}
