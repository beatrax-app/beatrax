<?php

declare(strict_types=1);

namespace Modules\Search\Public\Services;

use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Public facade exposing FTS5 index health to consumers outside Search Internal.
 *
 * This class lives in Search Public so that Core's DoctorCommand can depend on
 * it without crossing into Search Internal — maintaining the module boundary
 * enforced by BoundaryArchTest (Pitfall-7 / T-08-05).
 *
 * Design: returns plain PHP values (no Core Internal types) so DoctorCommand
 * can construct its own ProbeResult from this data without FtsHealthCheck
 * importing Core\Internal types (which would violate the arch boundary).
 *
 * Registered as a singleton by the Plan 01 class_exists()-guarded block in
 * SearchServiceProvider::register() — do NOT add a constructor binding here.
 */
final class FtsHealthCheck
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Human-readable label for the doctor output table.
     */
    public function label(): string
    {
        return 'FTS search index';
    }

    /**
     * Returns the probe severity for the doctor output table.
     *
     * Returns 'warning' when the index lags behind the table or the
     * index table is absent. Returns 'ok' when row counts match.
     *
     * @return 'ok'|'warning'
     */
    public function severity(): string
    {
        return $this->result()['severity'];
    }

    /**
     * Human-readable message for the doctor output table.
     *
     * Uses the UI-SPEC copywriting contract:
     *   ok:      "FTS index: {N} rows — in sync"
     *   warning: "FTS index: {N} rows — {delta} behind table"
     *   absent:  "FTS index table absent — run php artisan migrate then search:reindex"
     */
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
        } catch (Throwable) {
            return [
                'severity' => 'warning',
                'message' => 'FTS index table absent — run php artisan migrate then search:reindex',
            ];
        }

        $delta = $tableCount - $indexCount;

        if ($delta === 0) {
            return [
                'severity' => 'ok',
                'message' => "FTS index: {$indexCount} rows — in sync",
            ];
        }

        return [
            'severity' => 'warning',
            'message' => "FTS index: {$indexCount} rows — {$delta} behind table",
        ];
    }
}
