<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\Services\TransactionCursor;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// The candidate-id half of a search: turn the text query into a bounded
// id list via FTS5 MATCH, or — for queries too short for FTS5 — the
// decrypt-then-substring LIKE fallback, plus the highlight/snippet load
// that reuses the same MATCH expression.
final class FtsCandidateResolver
{
    use CoercesScalars;

    // The index tokenizer is trigram, so an FTS5 "token" is a three-character
    // window rather than a word: the twelve asked for here was a dozen
    // characters and halved the matched word, turning a search for
    // Rentevergoeding into "Rente…". 64 is FTS5's own ceiling.
    private const int SNIPPET_TRIGRAMS = 64;

    // Bounds the candidate window the <3-char LIKE fallback decrypts,
    // most-recent-first, so a short query never decrypts an entire
    // multi-year history on every keystroke.
    public const LIKE_FALLBACK_CANDIDATE_CAP = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
        private readonly EncryptionMigrationService $encryptionService,
    ) {}

    // Returns null for the empty-text (filters-only) branch to signal
    // "apply no id restriction"; otherwise a bounded matched-id list via
    // FTS5 MATCH or the LIKE fallback, whose scan runs the caller's
    // filter routine so it honours the same active filters as the query.
    /**
     * @param  Closure(Builder): void  $applyFilters
     * @return list<int>|null
     */
    public function resolve(User $user, string $textQuery, Closure $applyFilters): ?array
    {
        if ($textQuery === '') {
            return null;
        }

        $searchable = self::separateControlBytes($textQuery);

        // Short words (1-2 chars) cannot be MATCH predicates -- the trigram
        // tokenizer needs three characters -- but they still have to narrow.
        // Dropping them made a query WIDER than the words the reader typed:
        // "de la place" returned exactly what "la place" did, and a term
        // present nowhere in the index changed nothing at all. That is the
        // same failure typed `account:` tokens were fixed for.
        $ftsWords = $this->significantFtsWords($searchable);
        $shortWords = $this->shortFtsWords($searchable);
        if (mb_strlen($searchable) < 3 || $ftsWords === []) {
            return $this->likeFallbackIds($user, $searchable, $applyFilters);
        }

        $query = $this->db->connection()
            ->table('transaction_search_fts')
            ->whereRaw('transaction_search_fts MATCH ?', [$this->ftsMatchExpression($ftsWords)])
            ->join(
                'transaction_search_docs',
                'transaction_search_docs.transaction_id',
                '=',
                'transaction_search_fts.rowid',
            )
            ->where('transaction_search_docs.user_id', $user->id);

        foreach ($shortWords as $word) {
            $query->where('transaction_search_docs.search_body', 'like', '%'.self::escapeLike($word).'%');
        }

        $rowids = $query->pluck('transaction_search_fts.rowid')->all();

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rowids));
    }

    /**
     * @param  list<int>  $rowIds
     * @return array<int, stdClass>
     */
    public function loadHighlights(User $user, string $textQuery, array $rowIds): array
    {
        if ($rowIds === []) {
            return [];
        }

        $ftsWords = $this->significantFtsWords(self::separateControlBytes($textQuery));
        if ($ftsWords === []) {
            return [];
        }
        $ftsMatch = $this->ftsMatchExpression($ftsWords);

        $highlightRows = $this->db->connection()
            ->table('transaction_search_fts')
            ->selectRaw(
                'transaction_search_fts.rowid,
                 highlight(transaction_search_fts, 0, ?, ?) AS highlighted_body,
                 snippet(transaction_search_fts, 0, ?, ?, ?, ?) AS snippet_body',
                [
                    HighlightSentinels::START,
                    HighlightSentinels::END,
                    HighlightSentinels::START,
                    HighlightSentinels::END,
                    '…',
                    self::SNIPPET_TRIGRAMS,
                ],
            )
            ->whereRaw('transaction_search_fts MATCH ?', [$ftsMatch])
            ->join(
                'transaction_search_docs',
                'transaction_search_docs.transaction_id',
                '=',
                'transaction_search_fts.rowid',
            )
            ->where('transaction_search_docs.user_id', $user->id)
            ->whereIn('transaction_search_fts.rowid', $rowIds)
            ->get();

        /** @var array<int, stdClass> $result */
        $result = [];
        foreach ($highlightRows as $hl) {
            $result[self::toInt($hl->rowid)] = $hl;
        }

        return $result;
    }

    // Fallback for queries too short for FTS5. Narrows the candidate set
    // in SQL on cheap plaintext dimensions only, then decrypts +
    // substring-matches in PHP (a no-op pass-through when disabled).
    /**
     * @param  Closure(Builder): void  $applyFilters
     * @return list<int>
     */
    private function likeFallbackIds(User $user, string $textQuery, Closure $applyFilters): array
    {
        $query = $this->db->connection()
            ->table('transactions')
            ->where('transactions.user_id', $user->id);

        $applyFilters($query);
        TransactionCursor::orderNewestFirst($query);

        /** @var iterable<stdClass> $candidates */
        $candidates = $query
            ->limit(self::LIKE_FALLBACK_CANDIDATE_CAP)
            ->get(['transactions.id', 'transactions.counterparty_name', 'transactions.description']);

        $needle = mb_strtolower($textQuery);
        $userId = $user->id;
        $encryptionEnabled = $this->encryptionService->isEnabled($userId);

        $matched = [];
        foreach ($candidates as $row) {
            $haystack = $this->decryptedRowHaystack($row, $userId, $encryptionEnabled);
            if ($haystack !== null && str_contains($haystack, $needle)) {
                $matched[] = self::toInt($row->id);
            }
        }

        return $matched;
    }

    // Lowercased "name\0description" for one candidate row, or null when
    // encryption is on and either field is still ciphertext (rekey/epoch
    // gap, or a locked app-lock) — the NUL join can never be spanned by
    // a user needle, so a hit lies wholly within one field.
    private function decryptedRowHaystack(stdClass $row, int $userId, bool $encryptionEnabled): ?string
    {
        $nameResult = $this->decryptField('transactions', 'counterparty_name', $row->counterparty_name ?? null, $userId);
        $descriptionResult = $this->decryptField('transactions', 'description', $row->description ?? null, $userId);

        if ($encryptionEnabled && (! $nameResult['decrypted'] || ! $descriptionResult['decrypted'])) {
            return null;
        }

        return mb_strtolower($nameResult['value'])."\x00".mb_strtolower($descriptionResult['value']);
    }

    // Empty non-null stored values short-circuit to a decrypted pass so
    // callers never feed a blank blob to the codec.
    /**
     * @return array{value: string, decrypted: bool}
     */
    private function decryptField(string $table, string $column, mixed $stored, int $userId): array
    {
        if (! is_string($stored) || $stored === '') {
            return ['value' => '', 'decrypted' => true];
        }

        return $this->codec->decryptValue($table, $column, $stored, $userId, ($this->session)());
    }

    /**
     * @return list<string> the query's words of at least 3 CHARACTERS
     */
    private function significantFtsWords(string $textQuery): array
    {
        // Characters, not bytes. A two-letter accented word is three bytes, so
        // a byte count sent it to FTS5, whose trigram tokenizer needs three
        // characters and matched nothing -- and because words are AND-joined,
        // it took every other term in the query down with it. "Ze" with an
        // acute was unfindable and made "bar" unfindable beside it.
        return array_values(array_filter(
            explode(' ', trim($textQuery)),
            static fn (string $w): bool => mb_strlen($w) >= 3,
        ));
    }

    /**
     * @return list<string> the query's non-empty words of fewer than 3 characters
     */
    private function shortFtsWords(string $textQuery): array
    {
        return array_values(array_filter(
            explode(' ', trim($textQuery)),
            static fn (string $w): bool => $w !== '' && mb_strlen($w) < 3,
        ));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    // The reader's text is a URL parameter, so it can carry bytes no keyboard
    // sends. A NUL inside the quoted MATCH expression ends it early and FTS5
    // raises "unterminated string"; the same byte is the field join below, so
    // both readers of the query text get the substitution, not just FTS.
    private static function separateControlBytes(string $textQuery): string
    {
        return preg_replace('/[\x00-\x1F\x7F]+/', ' ', $textQuery) ?? $textQuery;
    }

    // Each word is double-quote-wrapped with embedded quotes doubled, the
    // standard FTS5 MATCH escaping, then AND-joined.
    /**
     * @param  list<string>  $ftsWords
     */
    private function ftsMatchExpression(array $ftsWords): string
    {
        $escaped = array_map(
            static fn (string $w): string => '"'.str_replace('"', '""', $w).'"',
            $ftsWords,
        );

        return implode(' AND ', $escaped);
    }
}
