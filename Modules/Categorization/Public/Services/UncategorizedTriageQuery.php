<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Categorization\Public\Dto\TriageBatch;
use Modules\Categorization\Public\Dto\TriageRow;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\Services\TransactionCursor;
use Modules\Ledger\Public\Support\LedgerDay;
use Modules\Ledger\Public\Support\SplitLegs;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

final readonly class UncategorizedTriageQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
    ) {}

    public function for(User $user, int $limit = 50, ?int $cursorId = null, ?string $cursorPostedAt = null): TriageBatch
    {
        $query = $this->db->connection()
            ->table('transactions')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->where('transactions.user_id', $user->id)
            ->whereNull('transactions.category_id')
            ->tap(static fn ($q): Builder => SplitLegs::excludeParents($q))
            ->select([
                'transactions.id',
                'transactions.posted_at',
                'transactions.counterparty_name',
                'transactions.amount_minor',
                'transactions.currency',
                'transactions.description',
                'counterparties.slug as counterparty_slug',
            ])
            ->limit($limit + 1);

        TransactionCursor::orderNewestFirst($query);
        TransactionCursor::apply($query, $cursorPostedAt, $cursorId);

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
        $postedAt = CarbonImmutable::parse(self::toString($row->posted_at));
        $counterpartyName = $row->counterparty_name === null
            ? null
            : $this->codec->decryptValue('transactions', 'counterparty_name', self::toString($row->counterparty_name), $userId, ($this->session)())['value'];
        $description = $row->description === null
            ? null
            : $this->codec->decryptValue('transactions', 'description', self::toString($row->description), $userId, ($this->session)())['value'];
        $counterpartySlug = property_exists($row, 'counterparty_slug') && $row->counterparty_slug !== null
            ? self::toString($row->counterparty_slug)
            : null;
        // An empty slug would emit a dead-end /counterparties/ URL; null makes
        // the Blade fall back to plain text.
        if ($counterpartySlug === '') {
            $counterpartySlug = null;
        }

        return new TriageRow(
            transactionId: self::toInt($row->id),
            postedAt: LedgerDay::shown($postedAt),
            counterpartyName: $counterpartyName,
            amountMinor: self::toInt($row->amount_minor),
            currency: self::toString($row->currency),
            description: $description,
            counterpartySlug: $counterpartySlug,
        );
    }
}
