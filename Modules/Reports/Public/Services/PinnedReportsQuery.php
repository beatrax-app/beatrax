<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Support\DefinitionJsonDecoder;
use Modules\Reports\Public\Dto\ReportDefinition;
use stdClass;
use Throwable;

/**
 * @link ../../../../.docs/features/reports/architecture.md
 */
final readonly class PinnedReportsQuery
{
    // TogglePin already enforces the 3-pin cap in the write layer; this
    // query's own LIMIT is a second, independent enforcement point so a
    // stray fourth pinned row can never render a 4th mini card.
    private const MAX_PINS = 3;

    public function __construct(private DatabaseManager $db) {}

    /**
     * @return list<array{id: int, name: string, definition: ReportDefinition}>
     */
    public function forUser(User $user): array
    {
        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('saved_reports')
            ->where('user_id', $user->id)
            ->where('pinned', true)
            ->orderBy('pin_order')
            ->limit(self::MAX_PINS)
            ->get(['id', 'name', 'definition']);

        $result = [];
        foreach ($rows as $row) {
            $id = is_numeric($row->id ?? null) ? (int) $row->id : 0;
            if ($id === 0) {
                continue;
            }

            $name = is_string($row->name ?? null) ? $row->name : '';
            $definitionArray = DefinitionJsonDecoder::decode($row->definition ?? null);

            try {
                $definition = ReportDefinition::from($definitionArray);
            } catch (Throwable) {
                // A malformed/incomplete definition must never break the
                // dashboard — skip the row rather than 500, mirroring the
                // aggregation layer's own never-crash-the-dashboard posture.
                continue;
            }

            $result[] = [
                'id' => $id,
                'name' => $name,
                'definition' => $definition,
            ];
        }

        return $result;
    }
}
