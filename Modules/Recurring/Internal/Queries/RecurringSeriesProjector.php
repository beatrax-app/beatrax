<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Queries;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Recurring\Internal\Mapping\RecurringSeriesDtoMapper;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use stdClass;

// Turns recurring_series rows into RecurringSeriesDto — both the single-row
// hydration and the paged, state-scoped keyset walk. Extracted from
// RecurringSeriesQuery so that class stays under the method-count ceiling;
// row-to-DTO projection is a cohesive slice of its own.
final readonly class RecurringSeriesProjector
{
    use CoercesScalars;

    public function __construct(private DatabaseManager $db) {}

    /**
     * @param  list<string>  $states
     * @return list<RecurringSeriesDto>
     */
    public function scoped(User $user, array $states, ?int $cursorId, int $limit, string $primarySort): array
    {
        $query = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('state', $states)
            ->limit($limit);

        if ($primarySort === 'monthly_equivalent_minor') {
            $query->orderByDesc('monthly_equivalent_minor')->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        if ($cursorId !== null) {
            if ($primarySort === 'monthly_equivalent_minor') {
                // Composite cursor: the next page is every row whose value is
                // strictly smaller than the cursor row's, plus rows that tie
                // the cursor but whose id sorts lower — otherwise rows are
                // skipped or repeated when neighbours share the same value.
                $cursorRow = $this->db->connection()->table('recurring_series')
                    ->where('id', $cursorId)
                    ->first(['monthly_equivalent_minor']);
                if ($cursorRow !== null) {
                    $cursorEq = self::toInt($cursorRow->monthly_equivalent_minor);
                    $query->where(function (Builder $q) use ($cursorEq, $cursorId): void {
                        $q->where('monthly_equivalent_minor', '<', $cursorEq)
                            ->orWhere(function (Builder $q2) use ($cursorEq, $cursorId): void {
                                $q2->where('monthly_equivalent_minor', $cursorEq)
                                    ->where('id', '<', $cursorId);
                            });
                    });
                }
            } else {
                $query->where('id', '<', $cursorId);
            }
        }

        $rows = $query->get();
        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = $this->toDto($row);
        }

        return $result;
    }

    public function toDto(stdClass $row): RecurringSeriesDto
    {
        // RecurringSeriesQuery reads the raw chain-link column with
        // no occurrence-walk fallback — that fallback lives only in
        // FixedPaymentsViewQuery where it is load-bearing.
        $chainLinkId = isset($row->latest_funding_chain_link_id)
            ? self::toInt($row->latest_funding_chain_link_id)
            : null;

        return RecurringSeriesDtoMapper::hydrate($row, $chainLinkId);
    }
}
