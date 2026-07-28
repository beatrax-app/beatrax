<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// Suggests one "did you mean" word (levenshtein <= 2, no spellfix1 in
// this SQLite build) for a zero-result query >= 4 chars, built from a
// decrypt-then-tally corpus over a bounded most-recent window.
/**
 * @link ../../../../.docs/features/search/architecture.md
 */
final class DidYouMeanSuggester
{
    // Bounds the number of raw rows decrypted to build the corpus so a
    // single suggestion never decrypts an entire multi-year history.
    private const CANDIDATE_ROW_CAP = 2000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
        private readonly EncryptionMigrationService $encryptionService,
    ) {}

    public function suggest(User $user, string $query): ?string
    {
        $query = trim($query);

        if (strlen($query) < 4) {
            return null;
        }

        $rawWords = explode(' ', $query);
        $words = array_values(array_filter($rawWords, static fn (string $w): bool => $w !== ''));
        if ($words === []) {
            return null;
        }
        $targetWord = strtolower($words[count($words) - 1]);

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

        // Keys are corpus words; PHP coerces purely-numeric word keys
        // to int, so the key type is array-key, not string (normalized
        // back below).
        /** @var array<array-key, int> $corpusWords */
        $corpusWords = [];
        foreach ($names as $name) {
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

        $bestWord = null;
        $bestDist = PHP_INT_MAX;
        $bestFreq = -1;

        foreach ($corpusWords as $corpusWord => $freq) {
            $word = (string) $corpusWord;

            if ($word === $targetWord) {
                // An exact word is not a suggestion candidate for
                // itself, but does not suppress suggestions derived
                // from other corpus words.
                continue;
            }

            $dist = levenshtein($targetWord, $word);
            if ($dist < $bestDist || ($dist === $bestDist && $freq > $bestFreq)) {
                $bestDist = $dist;
                $bestFreq = $freq;
                $bestWord = $word;
            }
        }

        if ($bestDist <= 2 && $bestWord !== null) {
            return $bestWord;
        }

        return null;
    }
}
