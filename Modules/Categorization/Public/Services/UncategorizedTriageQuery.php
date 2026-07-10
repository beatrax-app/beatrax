<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Public\Dto\TriageBatch;
use Modules\Categorization\Public\Dto\TriageRow;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
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
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    public function for(User $user, int $limit = 50, ?int $cursorId = null, ?string $cursorPostedAt = null): TriageBatch
    {
        // Left-join `counterparties` so each row carries its resolved
        // counterparty slug in a single query (no N+1 expansion across
        // the triage page render). Rows with counterparty_id NULL
        // yield NULL on the joined slug and the Blade falls back to
        // plain-text rendering of the counterparty name.
        $query = $this->db->connection()
            ->table('transactions')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->where('transactions.user_id', $user->id)
            ->whereNull('transactions.category_id')
            ->orderByDesc('transactions.posted_at')
            ->orderByDesc('transactions.id')
            ->select([
                'transactions.id',
                'transactions.posted_at',
                'transactions.booked_at',
                'transactions.counterparty_name',
                'transactions.amount_minor',
                'transactions.currency',
                'transactions.description',
                'counterparties.slug as counterparty_slug',
            ])
            ->limit($limit + 1);

        if ($cursorId !== null) {
            if ($cursorPostedAt === null) {
                $query->where('transactions.id', '<', $cursorId);
            } else {
                $query->whereRaw('(transactions.posted_at, transactions.id) < (?, ?)', [$cursorPostedAt, $cursorId]);
            }
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $sliced = $rows->take($limit)->values();

        $dtos = [];
        $lastId = null;
        $lastPostedAt = null;
        foreach ($sliced as $row) {
            $dtos[] = $this->mapRow($row, $user->id);
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

    private function mapRow(stdClass $row, int $userId): TriageRow
    {
        $bookedAt = CarbonImmutable::parse(self::toString($row->booked_at));
        // D-06 (14.1-10) read-side decrypt — pass-through no-op when
        // encryption is not enabled for this user.
        $counterpartyName = $row->counterparty_name === null
            ? null
            : $this->codec->decryptValue('transactions', 'counterparty_name', self::toString($row->counterparty_name), $userId, $this->session)['value'];
        $description = $row->description === null
            ? null
            : $this->codec->decryptValue('transactions', 'description', self::toString($row->description), $userId, $this->session)['value'];
        $counterpartySlug = property_exists($row, 'counterparty_slug') && $row->counterparty_slug !== null
            ? self::toString($row->counterparty_slug)
            : null;
        // An empty slug yields no profile-page target; defensive-cast
        // it to null so the Blade falls back to plain text instead of
        // emitting a /counterparties/ dead-end URL.
        if ($counterpartySlug === '') {
            $counterpartySlug = null;
        }

        return new TriageRow(
            transactionId: self::toInt($row->id),
            bookedAt: $bookedAt->format('d-m-Y'),
            counterpartyName: $counterpartyName,
            amountMinor: self::toInt($row->amount_minor),
            currency: self::toString($row->currency),
            description: $description,
            counterpartySlug: $counterpartySlug,
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
