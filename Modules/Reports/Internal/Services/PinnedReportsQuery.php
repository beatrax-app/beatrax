<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Support\PinCap;
use Modules\Reports\Internal\Support\ReportDefinitionFactory;
use stdClass;

final readonly class PinnedReportsQuery
{
    // A second enforcement point independent of TogglePin's write-layer cap, so
    // a stray fourth pinned row can never render a fourth mini card.
    private const int MAX_PINS = PinCap::MAX_PINS;

    public function __construct(private DatabaseManager $db) {}

    /**
     * @return list<array{id: int, name: string, definition: ReportDefinition}>
     */
    public function forUser(User $user): array
    {
        /** @var iterable<stdClass> $rows */
        // saved_reports replicates, and pin_order carries no UNIQUE, so two
        // devices pinning at once converge on one value. Without the id
        // tiebreak the MAX_PINS cut drops a different report on each.
        $rows = $this->db->connection()->table('saved_reports')
            ->where('user_id', $user->id)
            ->where('pinned', true)
            ->orderBy('pin_order')
            ->orderBy('id')
            ->limit(self::MAX_PINS)
            ->get(['id', 'name', 'definition']);

        $result = [];
        foreach ($rows as $row) {
            $id = is_numeric($row->id ?? null) ? (int) $row->id : 0;
            if ($id === 0) {
                continue;
            }

            $result[] = [
                'id' => $id,
                'name' => is_string($row->name ?? null) ? $row->name : '',
                'definition' => ReportDefinitionFactory::fromStored($row->definition ?? null),
            ];
        }

        return $result;
    }
}
