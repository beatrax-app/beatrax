<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\SetsTransactionNote;

/**
 * Mutates `transactions.note` for a transaction owned by the given user.
 * Exposed as a Public contract (T-13.4-13b — module boundary fix) so both
 * `TransactionDetail` (manual note editing) and, in Plan 05, the rule
 * engine, can drive note writes without either reaching into Ledger
 * internals or writing raw SQL against another module's table.
 *
 * PURE guarded write — mirrors `UpdateTransactionCategory` exactly: no
 * event dispatch, no provenance stamp. The caller owns emission
 * (`TransactionMutated`) and provenance stamping (`FieldProvenanceWriter`).
 *
 * `mode`:
 *   - `'set'` — trimmed `$text` replaces the note outright; a blank/empty
 *     input normalises to `NULL` (mirrors `TransactionDetail::saveNote()`'s
 *     existing trim/null handling).
 *   - `'append'` — trimmed `$text` is concatenated onto the current note
 *     separated by a newline; appending onto a null/empty note is
 *     equivalent to `set`; appending a blank/empty `$text` is a no-op.
 *
 * Guards, in order:
 *   - D-08 reconciled lock: `status === 'reconciled'` → 0, no write.
 *   - Write-only-on-change: the resolved target value already equals the
 *     current note → 0, no redundant write.
 */
final class SetTransactionNote implements SetsTransactionNote
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function __invoke(int $transactionId, ?string $text, string $mode, User $user): int
    {
        $row = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first(['status', 'note']);

        if ($row === null) {
            return 0;
        }

        if ($row->status === 'reconciled') {
            return 0;
        }

        $currentNote = is_string($row->note) ? $row->note : null;
        $trimmed = $text === null ? '' : trim($text);

        $target = $mode === 'append'
            ? self::appended($currentNote, $trimmed)
            : ($trimmed === '' ? null : $trimmed);

        if ($target === $currentNote) {
            return 0;
        }

        return Transaction::query()
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->update(['note' => $target]);
    }

    /**
     * Concatenates `$trimmed` onto `$currentNote` separated by a newline.
     * A blank `$trimmed` no-ops (returns `$currentNote` unchanged). A
     * null/empty `$currentNote` collapses to a plain `set` (no leading
     * separator).
     */
    private static function appended(?string $currentNote, string $trimmed): ?string
    {
        if ($trimmed === '') {
            return $currentNote;
        }

        if ($currentNote === null || $currentNote === '') {
            return $trimmed;
        }

        return $currentNote."\n".$trimmed;
    }
}
