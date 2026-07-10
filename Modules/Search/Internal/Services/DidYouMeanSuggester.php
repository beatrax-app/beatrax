<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * Provides a single "did you mean" suggestion when a query returns zero results.
 *
 * Strategy (RESEARCH OQ3, D-21):
 *   1. Gate: only suggest when `strlen($query) >= 4`.
 *   2. Corpus: the user's most-recent counterparty_name values (bounded,
 *      CRYPT-01/D-06 — see CANDIDATE_ROW_CAP), decrypted then tokenized into
 *      individual words.
 *   3. Comparison: last word of the query vs every corpus word via PHP `levenshtein()`.
 *   4. Tie-break: equal-distance candidates are broken by corpus frequency
 *      (the more frequent merchant wins) so the suggestion is deterministic and
 *      useful (WR-06).
 *   5. Threshold: only suggest when the closest distance is <= 2.
 *   6. Return: the closest corpus word, or null when no close enough match found.
 *      An exact corpus-word match is skipped as a candidate (a word is not a
 *      "did you mean" for itself) but does NOT suppress suggestions derived from
 *      other corpus words (WR-06).
 *
 * spellfix1 is not available in this SQLite build — confirmed via environment check.
 * PHP levenshtein() built-in is the designated fallback (RESEARCH Environment Availability).
 */
final class DidYouMeanSuggester
{
    /**
     * CRYPT-01 (D-06) / RESEARCH Pitfall 3: `counterparty_name` is
     * ciphertext at rest once encryption is enabled, so the corpus can no
     * longer be built via a SQL `GROUP BY counterparty_name` aggregate — the
     * value must be decrypted per row before it can be tallied. Bound the
     * number of raw rows fetched (most-recent-first) so this never decrypts
     * an entire multi-year transaction history to build one suggestion.
     */
    private const CANDIDATE_ROW_CAP = 2000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
        private readonly EncryptionMigrationService $encryptionService,
    ) {}

    public function suggest(User $user, string $query): ?string
    {
        $query = trim($query);

        // Gate: only attempt suggestions for longer queries
        if (strlen($query) < 4) {
            return null;
        }

        // Split query into words; use the last word as the one most likely mistyped
        $rawWords = explode(' ', $query);
        $words = array_values(array_filter($rawWords, static fn (string $w): bool => $w !== ''));
        if ($words === []) {
            return null;
        }
        $targetWord = strtolower($words[count($words) - 1]);

        // Build the corpus: decrypt-then-tally counterparty names over a
        // bounded, most-recent candidate window (CRYPT-01/D-06). SQL can no
        // longer GROUP BY the ciphertext column, so this fetches raw
        // (ungrouped) rows narrowed on the cheap plaintext `user_id`/ordering
        // dimensions, decrypts each name in PHP, and tallies frequency there
        // — mirrors CounterpartyIndexQuery's PHP-side aggregation precedent.
        // `decryptValue()` is a documented pass-through no-op when
        // encryption is not enabled, so this stays behavior-identical (aside
        // from the row-cap trade-off) to the old SQL GROUP BY for a
        // plaintext user.
        $names = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereNotNull('counterparty_name')
            ->where('counterparty_name', '!=', '')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(self::CANDIDATE_ROW_CAP)
            ->pluck('counterparty_name');

        $userId = $user->id;
        $encryptionEnabled = $this->encryptionService->isEnabled($userId);

        // Tokenize decrypted names into individual words, keeping each word's
        // accumulated corpus frequency so distance ties can be broken by
        // frequency (WR-06).
        // Keys are corpus words; PHP coerces purely-numeric word keys to int, so
        // the key type is array-key, not string (normalized back below).
        /** @var array<array-key, int> $corpusWords */
        $corpusWords = [];
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            $result = $this->codec->decryptValue('transactions', 'counterparty_name', $name, $userId, $this->session);

            // WR-18: when encryption is enabled, a decrypted:false result is
            // ciphertext (rekey/epoch gap, or a locked app-lock) — skip it
            // rather than tokenizing a ciphertext blob into the suggestion
            // corpus. Non-encryption users keep the plaintext pass-through.
            if ($encryptionEnabled && ! $result['decrypted']) {
                continue;
            }

            $decrypted = $result['value'];
            if ($decrypted === '') {
                continue;
            }
            $tokens = preg_split('/\s+/', strtolower($decrypted));
            if ($tokens === false) {
                continue;
            }
            foreach ($tokens as $word) {
                $word = trim($word);
                if (strlen($word) >= 3) {
                    $corpusWords[$word] = ($corpusWords[$word] ?? 0) + 1;
                }
            }
        }

        if ($corpusWords === []) {
            return null;
        }

        // Find the closest corpus word by levenshtein distance, breaking ties by
        // corpus frequency (more frequent merchant wins). WR-06: do NOT abort the
        // whole search on an exact corpus-word match — the zero-result was caused
        // by a different query word or a filter, so skipping the exact word and
        // suggesting for the rest is still useful. We simply skip the exact word
        // as a candidate (it cannot be a "did you mean" for itself).
        $bestWord = null;
        $bestDist = PHP_INT_MAX;
        $bestFreq = -1;

        foreach ($corpusWords as $corpusWord => $freq) {
            // Normalize the (possibly int-coerced) key back to a string so
            // levenshtein() and the (string-typed) target comparison are exact.
            $word = (string) $corpusWord;

            if ($word === $targetWord) {
                // Exact word — not a suggestion candidate, but does not suppress
                // suggestions derived from other corpus words.
                continue;
            }

            $dist = levenshtein($targetWord, $word);
            if ($dist < $bestDist || ($dist === $bestDist && $freq > $bestFreq)) {
                $bestDist = $dist;
                $bestFreq = $freq;
                $bestWord = $word;
            }
        }

        // Only suggest when close enough (levenshtein <= 2)
        if ($bestDist <= 2 && $bestWord !== null) {
            return $bestWord;
        }

        return null;
    }
}
