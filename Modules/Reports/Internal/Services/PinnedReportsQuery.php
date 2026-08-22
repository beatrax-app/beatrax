<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Support\DefinitionJsonDecoder;
use Modules\Reports\Internal\Support\PinCap;
use stdClass;
use Throwable;

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
                // A malformed definition skips its row rather than 500 the
                // whole dashboard.
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
