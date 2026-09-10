<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// Suggests one "did you mean" word (edit distance <= 2, no spellfix1 in
// this SQLite build) for a zero-result query >= 4 characters, built from
// a decrypt-then-tally corpus over a bounded most-recent window.
final readonly class DidYouMeanSuggester
{
    // Bounds the number of raw rows decrypted to build the corpus so a
    // single suggestion never decrypts an entire multi-year history.
    private const int CANDIDATE_ROW_CAP = 2000;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private EncryptionMigrationService $encryptionService,
    ) {}

    public function suggest(User $user, string $query): ?string
    {
        $targetWord = $this->targetWord($query);
        if ($targetWord === null) {
            return null;
        }

        $corpusWords = $this->buildCorpus($user);
        if ($corpusWords === []) {
            return null;
        }

        return $this->bestSuggestion($targetWord, $corpusWords);
    }

    // The suggestion target is the query's last whitespace-delimited
    // word, lowercased; a query under 4 chars or with no words has no
    // meaningful target and suppresses suggestions entirely.
    private function targetWord(string $query): ?string
    {
        $query = trim($query);
        if (mb_strlen($query) < 4) {
            return null;
        }

        $words = array_values(array_filter(
            explode(' ', $query),
            static fn (string $w): bool => $w !== '',
        ));

        return $words === [] ? null : mb_strtolower($words[count($words) - 1]);
    }

    // Decrypt-then-tally corpus over a bounded most-recent window: a
    // word => frequency map built from the user's counterparty names.
    /**
     * @return array<array-key, int> keyed by corpus word (numeric keys coerce to int)
     */
    private function buildCorpus(User $user): array
    {
        $userId = $user->id;
        $encryptionEnabled = $this->encryptionService->isEnabled($userId);

        $corpusWords = [];
        foreach ($this->recentCounterpartyNames($user) as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            $result = $this->codec->decryptValue('transactions', 'counterparty_name', $name, $userId, ($this->session)());

            // A decrypted:false result is ciphertext (rekey/epoch gap,
            // or a locked app-lock) — skip rather than tokenizing a
            // ciphertext blob into the corpus.
            if ($encryptionEnabled && ! $result['decrypted']) {
                continue;
            }

            foreach ($this->corpusTokens($result['value']) as $word) {
                $corpusWords[$word] = ($corpusWords[$word] ?? 0) + 1;
            }
        }

        return $corpusWords;
    }

    /**
     * @return Collection<int, mixed>
     */
    private function recentCounterpartyNames(User $user): Collection
    {
        return $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            // No `!= ''` beside it: sealed values are never the empty string,
            // so that half of the filter admitted every row once the column
            // was encrypted. buildCorpus() refuses a blank of either kind.
            ->whereNotNull('counterparty_name')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(self::CANDIDATE_ROW_CAP)
            ->pluck('counterparty_name');
    }

    /**
     * @return list<string> lowercased corpus tokens of at least 3 chars
     */
    private function corpusTokens(string $decrypted): array
    {
        if ($decrypted === '') {
            return [];
        }
        $tokens = preg_split('/\s+/', mb_strtolower($decrypted));
        if ($tokens === false) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', $tokens),
            static fn (string $w): bool => mb_strlen($w) >= SearchDocumentBody::TRIGRAM_WIDTH,
        ));
    }

    // Nearest corpus word within edit distance 2, ties broken by higher
    // frequency; the target word itself is never its own suggestion.
    /**
     * @param  array<array-key, int>  $corpusWords
     */
    private function bestSuggestion(string $targetWord, array $corpusWords): ?string
    {
        $bestWord = null;
        $bestDist = PHP_INT_MAX;
        $bestFreq = -1;

        foreach ($corpusWords as $corpusWord => $freq) {
            $word = (string) $corpusWord;
            if ($word === $targetWord) {
                continue;
            }

            $dist = self::editDistance($targetWord, $word);
            if ($dist < $bestDist || ($dist === $bestDist && $freq > $bestFreq)) {
                $bestDist = $dist;
                $bestFreq = $freq;
                $bestWord = $word;
            }
        }

        return $bestDist <= 2 ? $bestWord : null;
    }

    // levenshtein() counts bytes, so an accented character costs two edits
    // and the threshold above tightens the more accents a language has:
    // "eleve" sat four from its two-accent spelling, not two, and was never
    // offered. Single-byte pairs still take the C implementation.
    private static function editDistance(string $a, string $b): int
    {
        if (strlen($a) === mb_strlen($a) && strlen($b) === mb_strlen($b)) {
            return levenshtein($a, $b);
        }

        return self::codePointDistance(mb_str_split($a), mb_str_split($b));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function codePointDistance(array $a, array $b): int
    {
        $width = count($b);
        $previous = range(0, $width);

        foreach ($a as $i => $aChar) {
            $current = [$i + 1];
            foreach ($b as $j => $bChar) {
                $current[] = min(
                    $previous[$j + 1] + 1,
                    $current[$j] + 1,
                    $previous[$j] + ($aChar === $bChar ? 0 : 1),
                );
            }
            $previous = $current;
        }

        return $previous[$width];
    }
}
