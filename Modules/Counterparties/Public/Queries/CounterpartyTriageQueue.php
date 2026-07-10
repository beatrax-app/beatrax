<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * Read-side queue for `/counterparties/triage` — surfaces every
 * `type=unknown` counterparty awaiting identification, ranks them by
 * recency + transaction volume, and computes a per-row suggestion
 * banner shape using the existing MerchantNameResolver chain.
 *
 * The triage page renders these as a focused single-card queue with
 * keyboard-first ergonomics (Y / N / S / →). The `unknownCountForUser`
 * method feeds the sidebar amber badge.
 *
 * Cross-user posture: every read carries an explicit
 * `where('user_id', $user->id)` filter at the raw query-builder
 * boundary. The BelongsToUser global scope on the Counterparty model
 * is a secondary guard — the explicit filter is load-bearing because
 * the suggestion pipeline runs in HTTP-bound contexts where the scope
 * fires, but the rest of the codebase relies on the explicit filter
 * being present regardless.
 */
final readonly class CounterpartyTriageQueue
{
    /** Recency / volume scan cap so the per-render cost stays bounded. */
    private const SCAN_LIMIT = 200;

    /** Suggestion thresholds: high = ≥80% match; medium = ≥60% match. */
    private const CONFIDENCE_HIGH = 80;

    private const CONFIDENCE_MEDIUM = 60;

    public function __construct(
        private DatabaseManager $db,
        private MerchantNameResolver $merchantResolver,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    /**
     * Returns the ordered queue of unknown counterparties for the user.
     * When `$queueFirstId` is provided AND the id resolves to an
     * unknown counterparty for this user, that row is pinned to the
     * front of the queue (matches the `?queue_first={id}` link from
     * unknown index cards).
     *
     * @return list<Counterparty>
     */
    public function forUser(User $user, ?int $queueFirstId = null): array
    {
        // Walk the raw query builder + hydrate into Counterparty
        // models. Eloquent's `Builder->orderByDesc(...)` triggers a
        // PHPStan `staticMethod.dynamicCall` warning because the
        // builder is dynamic; the raw query builder + `hydrate(...)`
        // keeps the static-analysis surface clean while preserving
        // the model-rich return type.
        $rawRows = $this->db->connection()->table('counterparties')
            ->where('user_id', $user->id)
            ->where('type', 'unknown')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::SCAN_LIMIT)
            ->get();

        /** @var list<Counterparty> $rows */
        $rows = array_values(Counterparty::hydrate($rawRows->all())->all());

        if ($queueFirstId === null) {
            return $rows;
        }

        $head = null;
        /** @var list<Counterparty> $tail */
        $tail = [];
        foreach ($rows as $row) {
            if ($row->id === $queueFirstId) {
                $head = $row;

                continue;
            }
            $tail[] = $row;
        }

        if ($head === null) {
            return $rows;
        }

        return array_merge([$head], $tail);
    }

    /**
     * Computes a `TriageSuggestion` for the given unknown counterparty
     * by walking its recent transaction descriptions through the
     * `MerchantNameResolver`. Returns null when no description matches
     * a known merchant — the triage page then renders the manual-label
     * section without a suggestion banner.
     *
     * Confidence ladder:
     *   - high   when ≥80% of recent transactions resolve to the same merchant
     *   - medium when ≥60% match
     *   - low    when at least one match but below 60%
     *
     * The reasoning sub-line is load-bearing per UI-SPEC — the
     * suggestion is never rendered without it.
     */
    public function suggestionFor(Counterparty $unknown): ?TriageSuggestion
    {
        if ($unknown->user_id === null) {
            return null;
        }

        /** @var iterable<stdClass> $transactions */
        $transactions = $this->db->connection()->table('transactions')
            ->where('user_id', $unknown->user_id)
            ->where('counterparty_id', $unknown->id)
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['description']);

        /** @var array<string, int> $tally */
        $tally = [];
        $total = 0;
        foreach ($transactions as $tx) {
            $description = is_string($tx->description ?? null) ? $tx->description : '';
            if ($description === '') {
                continue;
            }
            // CRYPT-01 (14.1-13): decrypt each candidate description before
            // matching — the description arrives ciphertext at rest once
            // encryption is enabled, and substring/corpus matching against
            // ciphertext always misses. Pass-through no-op for a
            // non-encrypted user; the candidate set stays bounded to the
            // limit(20) read above (no query widening).
            $description = $this->codec->decryptValue('transactions', 'description', $description, $unknown->user_id, $this->session)['value'];
            $total++;
            $resolved = $this->merchantResolver->resolve($description, $unknown->user_id);
            if ($resolved === null) {
                continue;
            }
            $tally[$resolved] = ($tally[$resolved] ?? 0) + 1;
        }

        if ($total === 0 || $tally === []) {
            return null;
        }

        arsort($tally);
        // `$tally` is non-empty here (guarded above), so
        // array_key_first never returns null at this point.
        $topName = array_key_first($tally);
        $topHits = $tally[$topName];
        $sharePercent = (int) round(($topHits / $total) * 100);

        $confidence = match (true) {
            $sharePercent >= self::CONFIDENCE_HIGH => 'high',
            $sharePercent >= self::CONFIDENCE_MEDIUM => 'medium',
            default => 'low',
        };

        $reasoning = sprintf(
            '%d of %d recent transactions on this IBAN resolve to %s via the merchant name resolver.',
            $topHits,
            $total,
            $topName,
        );

        return new TriageSuggestion(
            suggestedCounterpartyName: $topName,
            confidence: $confidence,
            reasoning: $reasoning,
        );
    }

    /**
     * Returns the count of unknown counterparties for the user. Feeds
     * the sidebar `Triage` amber badge — hidden when zero, surfaced
     * otherwise so the user always sees how much triage work remains.
     */
    public function unknownCountForUser(User $user): int
    {
        return $this->db->connection()->table('counterparties')
            ->where('user_id', $user->id)
            ->where('type', 'unknown')
            ->count();
    }
}
