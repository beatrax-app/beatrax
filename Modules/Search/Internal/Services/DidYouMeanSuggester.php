<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

/**
 * Provides a single "did you mean" suggestion when a query returns zero results.
 *
 * Strategy (RESEARCH OQ3, D-21):
 *   1. Gate: only suggest when `strlen($query) >= 4`.
 *   2. Corpus: top ~1000 most-frequent counterparty_name values for the user,
 *      tokenized into individual words.
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
    public function __construct(
        private readonly DatabaseManager $db,
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

        // Build the corpus: top ~1000 most-frequent counterparty names for the user
        $names = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereNotNull('counterparty_name')
            ->where('counterparty_name', '!=', '')
            ->selectRaw('counterparty_name, COUNT(*) as freq')
            ->groupBy('counterparty_name')
            ->orderByDesc('freq')
            ->limit(1000)
            ->pluck('counterparty_name');

        // Tokenize names into individual words, keeping each word's accumulated
        // corpus frequency so distance ties can be broken by frequency (WR-06).
        // The pluck preserves the freq-descending order from the query, but the
        // first-seen order is not reliable for tie-breaks, so track freq instead
        // of collapsing to a bool map.
        // Keys are corpus words; PHP coerces purely-numeric word keys to int, so
        // the key type is array-key, not string (normalized back below).
        /** @var array<array-key, int> $corpusWords */
        $corpusWords = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }
            $tokens = preg_split('/\s+/', strtolower($name));
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
