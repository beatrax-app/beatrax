<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Public\Enums\NoteMode;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\SetsTransactionNote;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// mode='set': trimmed $text replaces the note outright, blank input
// normalises to NULL. mode='append': trimmed $text is concatenated
// onto the current note separated by a newline; appending onto a
// null/empty note is equivalent to set; a blank $text is a no-op.
final readonly class SetTransactionNote implements SetsTransactionNote
{
    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
    ) {}

    public function __invoke(int $transactionId, ?string $text, string $mode, User $user): int
    {
        $row = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first(['status', 'note']);

        // A missing row and a reconciled one are the same answer to the
        // caller: the note was not written, and not because of an error.
        if ($row === null || TransactionStatusQuery::locksEdits($row->status)) {
            return 0;
        }

        // The stored note is ciphertext under an encrypted user, so the
        // append/change-guard must operate on the decrypted plaintext,
        // never the raw stored value.
        $currentNote = is_string($row->note)
            ? $this->codec->decryptValue('transactions', 'note', $row->note, $user->id, ($this->session)())['value']
            : null;
        $trimmed = $text === null ? '' : trim($text);

        if ($mode === NoteMode::Append->value) {
            $target = self::appended($currentNote, $trimmed);
        } else {
            $target = $trimmed === '' ? null : $trimmed;
        }

        if ($target === $currentNote) {
            return 0;
        }

        return Transaction::query()
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->update([
                'note' => is_string($target)
                    ? $this->codec->encryptValue('transactions', 'note', $target, $user->id, ($this->session)())
                    : $target,
            ]);
    }

    private static function appended(?string $currentNote, string $trimmed): ?string
    {
        // A rule's note action re-runs over the same rows every time the reader
        // presses "re-apply to history". Unconditional concatenation made the
        // change guard above unreachable, so the note grew one line per run and
        // every run wrote an op every paired device replayed.
        return match (true) {
            $trimmed === '' => $currentNote,
            $currentNote === null || $currentNote === '' => $trimmed,
            in_array($trimmed, explode("\n", $currentNote), true) => $currentNote,
            default => $currentNote."\n".$trimmed,
        };
    }
}
