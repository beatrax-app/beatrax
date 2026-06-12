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
 *   4. Threshold: only suggest when the closest distance is <= 2.
 *   5. Return: the closest corpus word, or null when no close enough match found.
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

        // Tokenize names into individual words
        /** @var array<string, true> $corpusWords */
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
                    $corpusWords[$word] = true;
                }
            }
        }

        if ($corpusWords === []) {
            return null;
        }

        // Find the closest corpus word by levenshtein distance
        $bestWord = null;
        $bestDist = PHP_INT_MAX;

        foreach (array_keys($corpusWords) as $corpusWord) {
            // Skip exact matches (those would have been found by FTS)
            if ($corpusWord === $targetWord) {
                return null;
            }

            $dist = levenshtein($targetWord, $corpusWord);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestWord = $corpusWord;
            }
        }

        // Only suggest when close enough (levenshtein <= 2)
        if ($bestDist <= 2 && $bestWord !== null) {
            return $bestWord;
        }

        return null;
    }
}
