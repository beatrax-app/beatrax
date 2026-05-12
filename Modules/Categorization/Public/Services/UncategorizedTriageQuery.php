<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Public\Dto\TriageBatch;
use Modules\Categorization\Public\Dto\TriageRow;
use Modules\Core\Models\User;
use stdClass;

/**
 * Cursor-paginated read of transactions awaiting categorization. Powers
 * the `/uncategorized` triage inbox. Selects from the `transactions`
 * table directly via the raw query builder (rather than Eloquent) to
 * stay clean under `phpstan-strict-rules`' `staticMethod.dynamicCall`
 * rule and to keep the SELECT minimal — the triage UI needs only the
 * six columns rendered in the row DTO.
 *
 * Cursor pagination is a `(posted_at, id)` tuple compared via
 * `WHERE (posted_at, id) < (?, ?)`. The pair (rather than `id` alone)
 * is required because rows inserted in non-chronological order share
 * `posted_at` values, and a single-column id cursor would silently drop
 * them from later pages.
 */
final class UncategorizedTriageQuery
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function for(User $user, int $limit = 50, ?int $cursorId = null, ?string $cursorPostedAt = null): TriageBatch
    {
        $query = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->select([
                'id',
                'posted_at',
                'booked_at',
                'counterparty_name',
                'amount_minor',
                'currency',
                'description',
            ])
            ->limit($limit + 1);

        if ($cursorId !== null) {
            if ($cursorPostedAt === null) {
                $query->where('id', '<', $cursorId);
            } else {
                $query->whereRaw('(posted_at, id) < (?, ?)', [$cursorPostedAt, $cursorId]);
            }
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $sliced = $rows->take($limit)->values();

        $dtos = [];
        $lastId = null;
        $lastPostedAt = null;
        foreach ($sliced as $row) {
            $dtos[] = $this->mapRow($row);
            $lastId = self::toInt($row->id);
            $lastPostedAt = self::toString($row->posted_at);
        }

        return new TriageBatch(
            rows: $dtos,
            hasMore: $hasMore,
            nextCursorId: $hasMore ? $lastId : null,
            nextCursorPostedAt: $hasMore ? $lastPostedAt : null,
        );
    }

    private function mapRow(stdClass $row): TriageRow
    {
        $bookedAt = CarbonImmutable::parse(self::toString($row->booked_at));
        $counterpartyName = $row->counterparty_name === null
            ? null
            : self::toString($row->counterparty_name);
        $description = $row->description === null
            ? null
            : self::toString($row->description);

        return new TriageRow(
            transactionId: self::toInt($row->id),
            bookedAt: $bookedAt->format('d-m-Y'),
            counterpartyName: $counterpartyName,
            amountMinor: self::toInt($row->amount_minor),
            currency: self::toString($row->currency),
            description: $description,
        );
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
