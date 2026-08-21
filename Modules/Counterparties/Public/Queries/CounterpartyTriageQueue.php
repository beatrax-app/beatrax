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
 * @link ../../../../.docs/features/counterparties/triage-suggestions.md
 */
final readonly class CounterpartyTriageQueue
{
    // The queue is capped, not paged: a user with more than 200 unknowns
    // only ever sees the 200 most recently updated.
    private const SCAN_LIMIT = 200;

    private const CONFIDENCE_HIGH = 80;

    private const CONFIDENCE_MEDIUM = 60;

    public function __construct(
        private DatabaseManager $db,
        private MerchantNameResolver $merchantResolver,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    // $queueFirstId carries the `?queue_first={id}` param from an unknown
    // index card: that row is pinned to the front of the queue.
    /**
     * @return list<Counterparty>
     */
    public function forUser(User $user, ?int $queueFirstId = null): array
    {
        // Raw builder + hydrate(): Eloquent's dynamic orderByDesc() trips
        // larastan-strict `staticMethod.dynamicCall`, and this keeps the
        // model-rich return type anyway.
        $rawRows = $this->db->connection()->table('counterparties')
            ->where('user_id', $user->id)
            ->where('type', CounterpartyType::Unknown->value)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::SCAN_LIMIT)
            ->get();

        // display_name, merchant_name and iban are SensitiveFieldRegistry
        // columns; hydrate() applies no cast, so decrypt before hydrating.
        // Doing it first also makes plaintext the models' original state, so a
        // later save() never diffs re-encrypted values against ciphertext.
        $decryptedRows = array_map(
            fn (stdClass $row): stdClass => $this->decrypted($row, $user->id),
            $rawRows->all(),
        );

        /** @var list<Counterparty> $rows */
        $rows = array_values(Counterparty::hydrate($decryptedRows)->all());

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

    private function decrypted(stdClass $row, int $userId): stdClass
    {
        $decrypted = $this->codec->decryptRow('counterparties', [
            'display_name' => $row->display_name ?? null,
            'merchant_name' => $row->merchant_name ?? null,
            'iban' => $row->iban ?? null,
        ], $userId, $this->session);

        $row->display_name = $decrypted['display_name'];
        $row->merchant_name = $decrypted['merchant_name'];
        $row->iban = $decrypted['iban'];

        return $row;
    }

    // Null when no description resolves to a known merchant; the triage page
    // then renders the manual-label section with no suggestion banner.
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
            // Decrypt before matching: substring/corpus matching against
            // ciphertext always misses.
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
        $topName = array_key_first($tally);
        $topHits = $tally[$topName];
        $sharePercent = (int) round(($topHits / $total) * 100);

        $confidence = match (true) {
            $sharePercent >= self::CONFIDENCE_HIGH => 'high',
            $sharePercent >= self::CONFIDENCE_MEDIUM => 'medium',
            default => 'low',
        };

        // Translated, not sprintf'd: this sentence renders directly beneath a
        // localised suggestion banner, and an English format string put two
        // languages in one card.
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

    public function unknownCountForUser(User $user): int
    {
        return $this->db->connection()->table('counterparties')
            ->where('user_id', $user->id)
            ->where('type', CounterpartyType::Unknown->value)
            ->count();
    }
}
