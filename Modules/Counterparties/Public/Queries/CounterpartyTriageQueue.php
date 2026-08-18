<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../.docs/features/counterparties/architecture.md
 */
final readonly class CounterpartyTriageQueue
{
    // Recency/volume scan cap so the per-render cost stays bounded
    // regardless of how many unknown counterparties the user has.
    private const SCAN_LIMIT = 200;

    // Suggestion confidence thresholds — see the confidence ladder at
    // the @link above.
    private const CONFIDENCE_HIGH = 80;

    private const CONFIDENCE_MEDIUM = 60;

    public function __construct(
        private DatabaseManager $db,
        private MerchantNameResolver $merchantResolver,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    // When $queueFirstId resolves to an unknown counterparty for this
    // user, that row is pinned to the front of the queue (matches the
    // ?queue_first={id} link from unknown index cards).
    /**
     * @return list<Counterparty>
     */
    public function forUser(User $user, ?int $queueFirstId = null): array
    {
        // Raw query builder + hydrate(): Eloquent's dynamic
        // orderByDesc() triggers a PHPStan staticMethod.dynamicCall
        // warning, which this sidesteps while preserving the model-rich
        // return type.
        $rawRows = $this->db->connection()->table('counterparties')
            ->where('user_id', $user->id)
            ->where('type', CounterpartyType::Unknown->value)
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

    // Returns null when no description matches a known merchant; the
    // triage page then renders the manual-label section without a
    // suggestion banner. See the confidence ladder at the class @link.
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
            // Decrypt each candidate before matching — substring/corpus
            // matching against ciphertext always misses. A pass-through
            // no-op for a non-encrypted user; the candidate set stays
            // bounded to the limit(20) read above.
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

        // Translated, not sprintf'd: this sentence renders directly beneath a
        // localised suggestion banner, so an English format string put two
        // languages in one card. The resolver is named in the banner's own
        // copy, so the sentence carries only the counts and the name.
        $reasoning = Lang::get('counterparties::triage.reasoning', [
            'hits' => $topHits,
            'total' => $total,
            'name' => $topName,
        ]);

        return new TriageSuggestion(
            suggestedCounterpartyName: $topName,
            confidence: $confidence,
            reasoning: $reasoning,
        );
    }

    // Feeds the sidebar Triage amber badge — hidden when zero, surfaced
    // otherwise so the user always sees how much triage work remains.
    public function unknownCountForUser(User $user): int
    {
        return $this->db->connection()->table('counterparties')
            ->where('user_id', $user->id)
            ->where('type', CounterpartyType::Unknown->value)
            ->count();
    }
}
