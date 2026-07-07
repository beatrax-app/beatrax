<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Reports\Public\Dto\ReportDefinition;
use stdClass;
use Throwable;

/**
 * Read-side query powering the dashboard "pinned reports" mini-card row
 * (Req 10). Returns the caller's own pinned saved reports, ordered by
 * `pin_order`, capped at 3.
 *
 * `TogglePin` (Plan 07) already enforces the 3-pin cap in the write layer —
 * this query's own `LIMIT 3` is a second, independent enforcement point
 * (T-999.6-29, "never trust the cap from a single layer"), so a stray
 * fourth pinned row (e.g. a data anomaly, a future write-path bug) can
 * never render a 4th mini card on the dashboard.
 *
 * Cross-user posture: explicit `where('user_id', $user->id)` guard at the
 * raw query-builder boundary (999.6-PATTERNS.md "Cross-user isolation
 * guard", T-999.6-28) — a foreign id never reaches this query's caller.
 *
 * Raw `DatabaseManager` reads only (never Eloquent chains), matching every
 * other read-model class in this codebase (999.6-PATTERNS.md "Raw
 * DatabaseManager query discipline").
 */
final readonly class PinnedReportsQuery
{
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
            $definitionArray = self::decodeDefinition($row->definition ?? null);

            try {
                $definition = ReportDefinition::from($definitionArray);
            } catch (Throwable) {
                // A malformed/incomplete definition must never break the
                // dashboard — skip the row rather than 500 (Rule 2
                // robustness; mirrors the aggregation layer's own
                // never-crash-the-dashboard posture).
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

    /**
     * @return array<string, mixed>
     */
    private static function decodeDefinition(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
