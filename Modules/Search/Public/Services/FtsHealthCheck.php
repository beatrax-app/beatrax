<?php

declare(strict_types=1);

namespace Modules\Search\Public\Services;

use Illuminate\Database\DatabaseManager;
use Throwable;

// Lives in Public so Core's DoctorCommand can depend on it without
// crossing into Search Internal. Returns plain PHP values (no Core
// Internal types) so DoctorCommand builds its own ProbeResult.
final class FtsHealthCheck
{
    public function __construct(
        private readonly DatabaseManager $db,
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
