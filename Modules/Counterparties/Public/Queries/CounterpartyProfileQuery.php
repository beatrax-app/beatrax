<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

final readonly class CounterpartyProfileQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    public function bySlug(User $user, string $slug): ?CounterpartyProfileDto
    {
        $cp = Counterparty::query()
            ->where('user_id', $user->id)
            ->where('slug', $slug)
            ->first();

        if ($cp === null) {
            return null;
        }

        $cpId = $cp->id;
        $cutoffDate = $this->clock->now()->subYear()->toDateString();
        $connection = $this->db->connection();

        $totals = $connection->table('transactions')
            ->where('user_id', $user->id)
            ->where('counterparty_id', $cpId)
            ->where('posted_at', '>=', $cutoffDate)
            ->selectRaw('COALESCE(SUM(amount_minor), 0) as total, COUNT(*) as cnt')
            ->first();

        $lifetimeTotals = $connection->table('transactions')
            ->where('user_id', $user->id)
            ->where('counterparty_id', $cpId)
            ->selectRaw('MIN(posted_at) as first_seen, MAX(posted_at) as last_seen, COUNT(*) as cnt')
            ->first();

        $total12m = $totals !== null && is_numeric($totals->total ?? null) ? (int) $totals->total : 0;
        $txCount = $lifetimeTotals !== null && is_numeric($lifetimeTotals->cnt ?? null) ? (int) $lifetimeTotals->cnt : 0;
        $firstSeen = $lifetimeTotals !== null ? ($lifetimeTotals->first_seen ?? null) : null;
        $lastSeen = $lifetimeTotals !== null ? ($lifetimeTotals->last_seen ?? null) : null;

        $decrypted = $this->codec->decryptRow('counterparties', [
            'display_name' => $cp->display_name,
            'merchant_name' => $cp->merchant_name,
            'iban' => $cp->iban,
        ], $user->id, $this->session);

        $displayName = $decrypted['display_name'];
        $iban = $decrypted['iban'];
        $merchantName = $decrypted['merchant_name'];

        return new CounterpartyProfileDto(
            id: $cpId,
            slug: $cp->slug,
            displayName: is_string($displayName) ? $displayName : $cp->display_name,
            type: $cp->type,
            iban: is_string($iban) ? $iban : null,
            merchantName: is_string($merchantName) ? $merchantName : null,
            total12mMinor: $total12m,
            transactionCount: $txCount,
            firstSeenDate: is_string($firstSeen) ? substr($firstSeen, 0, 10) : null,
            lastSeenDate: is_string($lastSeen) ? substr($lastSeen, 0, 10) : null,
        );
    }

    /**
     * @return array{slug: string, displayName: string, type: string}|null
     */
    public function identityForId(User $user, int $id): ?array
    {
        $cp = Counterparty::query()
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->first(['slug', 'display_name', 'type']);

        if ($cp === null) {
            return null;
        }

        $displayName = $this->codec->decryptValue('counterparties', 'display_name', $cp->display_name, $user->id, $this->session)['value'];

        return [
            'slug' => $cp->slug,
            'displayName' => $displayName,
            'type' => $cp->type,
        ];
    }

    // Missing and cross-user ids are silently absent from the result map
    // rather than an error — callers must handle a short map.
    /**
     * @param  list<int>  $ids
     * @return array<int, array{slug: string, displayName: string, type: string}>
     */
    public function identitiesForIds(User $user, array $ids): array
    {
        $clean = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($clean === []) {
            return [];
        }

        // Raw builder: the Eloquent\Builder __call → Query\Builder forwarding
        // on whereIn() trips larastan-strict `staticMethod.dynamicCall`. The
        // explicit user_id filter is the only cross-user scope on this path.
        $rows = $this->db->connection()->table('counterparties')
            ->where('user_id', $user->id)
            ->whereIn('id', $clean)
            ->get(['id', 'slug', 'display_name', 'type']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $id = is_numeric($row->id) ? (int) $row->id : 0;
            if ($id <= 0) {
                continue;
            }
            $displayName = is_string($row->display_name)
                ? $this->codec->decryptValue('counterparties', 'display_name', $row->display_name, $user->id, $this->session)['value']
                : '';
            $map[$id] = [
                'slug' => is_string($row->slug) ? $row->slug : '',
                'displayName' => $displayName,
                'type' => is_string($row->type) ? $row->type : '',
            ];
        }

        return $map;
    }

    // Backs the calendar's cluster-key fallback: a recurring series with no
    // occurrence-linked counterparty can still match one by
    // cluster_counterparty_key == slug.
    /**
     * @param  list<string>  $slugs
     * @return array<string, int>
     */
    public function idsBySlugs(User $user, array $slugs): array
    {
        $clean = array_values(array_unique(array_filter($slugs, static fn (string $slug): bool => $slug !== '')));
        if ($clean === []) {
            return [];
        }

        // Raw builder for the same `staticMethod.dynamicCall` reason as above.
        $rows = $this->db->connection()->table('counterparties')
            ->where('user_id', $user->id)
            ->whereIn('slug', $clean)
            ->get(['id', 'slug']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $id = is_numeric($row->id) ? (int) $row->id : 0;
            if ($id > 0 && is_string($row->slug) && $row->slug !== '') {
                $map[$row->slug] = $id;
            }
        }

        return $map;
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function recentActivity(Counterparty $cp, int $limit = 10): Collection
    {
        $userId = (int) $cp->user_id;

        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $cp->user_id)
            ->where('counterparty_id', $cp->id)
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'posted_at', 'description', 'amount_minor', 'currency']);

        $decrypted = $rows->map(function (stdClass $row) use ($userId): stdClass {
            if (is_string($row->description)) {
                $row->description = $this->codec->decryptValue('transactions', 'description', $row->description, $userId, $this->session)['value'];
            }

            return $row;
        });

        return new Collection($decrypted->all());
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function categoryBreakdown(Counterparty $cp): Collection
    {
        $cutoffDate = $this->clock->now()->subYear()->toDateString();

        $rows = $this->db->connection()->table('transactions as t')
            ->leftJoin('categories as c', 'c.id', '=', 't.category_id')
            ->where('t.user_id', $cp->user_id)
            ->where('t.counterparty_id', $cp->id)
            ->where('t.posted_at', '>=', $cutoffDate)
            ->selectRaw('t.category_id as category_id, c.name as category_name, c.slug as category_slug, c.name_is_default as category_name_is_default, COALESCE(SUM(t.amount_minor), 0) as total_minor')
            ->groupBy('t.category_id', 'c.name', 'c.slug', 'c.name_is_default')
            ->orderByRaw('ABS(SUM(t.amount_minor)) DESC')
            ->get();

        // The breakdown goes straight to Blade, so the row carries the display
        // name rather than the stored one under the key the view already reads.
        $resolved = $rows->map(static function (stdClass $row): stdClass {
            $row->category_name = CategoryDisplayName::fromRow($row, 'category');

            return $row;
        });

        return new Collection($resolved->all());
    }

    // Nothing writes metadata.funding_chain yet, so this returns null in
    // practice and the merchant Overview renders its empty state.
    public function fundingChainSummary(Counterparty $cp): ?ChainSummary
    {
        $metadata = is_array($cp->metadata) ? $cp->metadata : [];
        $payload = $metadata['funding_chain'] ?? null;
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        $headline = is_string($payload['headline'] ?? null) ? $payload['headline'] : '';
        $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];

        /** @var list<array{label: string, glyph: string|null}> $typedNodes */
        $typedNodes = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $label = is_string($node['label'] ?? null) ? $node['label'] : '';
            $glyph = is_string($node['glyph'] ?? null) ? $node['glyph'] : null;
            $typedNodes[] = ['label' => $label, 'glyph' => $glyph];
        }

        return new ChainSummary(headline: $headline, nodes: $typedNodes);
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function taxYearBreakdown(Counterparty $cp): Collection
    {
        if ($cp->type !== CounterpartyType::Government->value) {
            return new Collection;
        }

        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $cp->user_id)
            ->where('counterparty_id', $cp->id)
            ->selectRaw("CAST(strftime('%Y', posted_at) AS INTEGER) as year, COALESCE(SUM(amount_minor), 0) as total_minor")
            ->groupBy('year')
            ->orderByDesc('year')
            ->get();

        return new Collection($rows->all());
    }
}
