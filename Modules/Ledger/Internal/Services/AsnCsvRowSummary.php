<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Internal\Support\SweptRowSummary;

/**
 * @link ../../../../.docs/features/ingestion/asn-description-delimiters.md#knowing-there-is-nothing-to-do-cheaply
 */
final readonly class AsnCsvRowSummary
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
    ) {}

    // One aggregate over the same owner-scoped predicate the sweep itself
    // runs on, and the whole cost of an unlock that has nothing to do. It
    // reads no description, so an unopenable ledger answers it as cheaply as
    // an open one.
    public function for(int $userId): SweptRowSummary
    {
        /** @var object{row_count: mixed, id_sum: mixed}|null $row */
        $row = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $userId)
            ->where('source_format', SourceFormat::AsnCsv->value)
            ->selectRaw('COUNT(*) as row_count, COALESCE(SUM(id), 0) as id_sum')
            ->first();

        if ($row === null) {
            return new SweptRowSummary(0, 0);
        }

        return new SweptRowSummary(
            self::toInt($row->row_count),
            self::toInt($row->id_sum),
        );
    }
}
