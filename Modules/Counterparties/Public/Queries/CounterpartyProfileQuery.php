<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Counterparties\Models\Counterparty;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * Read-side query powering `/counterparties/{slug}` — the type-aware
 * profile page. The shell Livewire component branches the body view
 * into the matching per-type partial based on `CounterpartyProfileDto::type`.
 *
 * Cross-user posture is the load-bearing 404 contract: a slug owned
 * by a different user MUST return null (the route resolver then maps
 * this to a `404` not a `403`, so no signal is emitted that the slug
 * exists in another user's set). The `BelongsToUser` global scope on
 * the Counterparty model is honoured here as a defense-in-depth
 * secondary check; the explicit `where('user_id', ...)` filter on the
 * raw query is the primary scope.
 *
 * Personal-IBAN privacy default: `bySlug` returns the IBAN in the
 * DTO, but every page-rendering path branches on the user's explicit
 * Show IBAN click before echoing the value. The slug column never
 * carries the IBAN.
 */
final readonly class CounterpartyProfileQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    /**
     * Returns the profile DTO for the slug, scoped to the user.
     * Returns null when the slug is unknown OR belongs to another user
     * — the route resolver then renders a 404.
     */
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

        // CRYPT-01 (D-02b) read-side decrypt — display_name/merchant_name/
        // iban are ciphertext at rest once encryption is enabled for the
        // user; decryptRow tries every keyring epoch (rotation-safe) and
        // is a pass-through no-op otherwise.
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
     * Minimal identity (slug + display name + type) for a counterparty id, or
     * null when it does not belong to the user. Lets another surface — e.g. the
     * recurring series detail page — deep-link to a profile without loading the
     * full profile DTO.
     *
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

    /**
     * Batched variant of `identityForId` — minimal identity (slug +
     * display name + type) per counterparty id, resolved in a single
     * query. Missing or cross-user ids are silently absent from the
     * result map. Lets list surfaces (the calendar's entry map) hydrate
     * deep-links without N per-id lookups.
     *
     * @param  list<int>  $ids
     * @return array<int, array{slug: string, displayName: string, type: string}>
     */
    public function identitiesForIds(User $user, array $ids): array
    {
        $clean = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($clean === []) {
            return [];
        }

        // Raw query builder via DatabaseManager — sidesteps the
        // Eloquent\Builder __call → Query\Builder forwarding that triggers
        // larastan-strict `staticMethod.dynamicCall` on `whereIn()`. The
        // explicit user_id filter is the primary cross-user scope here.
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

    /**
     * Resolve counterparty ids for a list of slugs in one query — the
     * batched existence check behind the calendar's cluster-key fallback
     * (a series with no occurrence-linked counterparty may still match a
     * counterparty by `cluster_counterparty_key` == slug). Unknown or
     * cross-user slugs are silently absent from the result map.
     *
     * @param  list<string>  $slugs
     * @return array<string, int>
     */
    public function idsBySlugs(User $user, array $slugs): array
    {
        $clean = array_values(array_unique(array_filter($slugs, static fn (string $slug): bool => $slug !== '')));
        if ($clean === []) {
            return [];
        }

        // Raw query builder via DatabaseManager — same
        // `staticMethod.dynamicCall` sidestep as identitiesForIds above.
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
     * Returns up to `$limit` most-recent transactions tied to the
     * counterparty, ordered newest-first. Each row is a stdClass with
     * the columns the Overview Recent activity strip + the Transactions
     * tab body render.
     *
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

        // CRYPT-01 (D-02b) read-side decrypt — description is ciphertext
        // at rest once encryption is enabled; pass-through no-op otherwise.
        $decrypted = $rows->map(function (stdClass $row) use ($userId): stdClass {
            if (is_string($row->description)) {
                $row->description = $this->codec->decryptValue('transactions', 'description', $row->description, $userId, $this->session)['value'];
            }

            return $row;
        });

        return new Collection($decrypted->all());
    }

    /**
     * Returns the per-category breakdown for the counterparty's last
     * 12 months. Each row carries `category_id`, `category_name`, and
     * the signed `total_minor` sum.
     *
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
            ->selectRaw('t.category_id as category_id, c.name as category_name, COALESCE(SUM(t.amount_minor), 0) as total_minor')
            ->groupBy('t.category_id', 'c.name')
            ->orderByRaw('ABS(SUM(t.amount_minor)) DESC')
            ->get();

        return new Collection($rows->all());
    }

    /**
     * Returns a funding-chain summary for merchant counterparties.
     *
     * Returns a `ChainSummary` when the counterparty has a non-empty
     * `metadata.funding_chain` payload (the cross-module Chains
     * lookup that lands with plan 17-06c writes this key). Returns
     * null otherwise — the merchant Overview body then renders the
     * verbatim empty-state copy from UI-SPEC.
     */
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
     * Returns annual breakdown rows for government counterparties
     * (Belastingdienst, Gemeente, CJIB, etc.). Each row carries the
     * `year` integer and the signed `total_minor` sum.
     *
     * @return Collection<int, stdClass>
     */
    public function taxYearBreakdown(Counterparty $cp): Collection
    {
        if ($cp->type !== 'government') {
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
