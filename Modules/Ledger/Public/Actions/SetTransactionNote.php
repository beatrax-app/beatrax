<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\SetsTransactionNote;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// mode='set': trimmed $text replaces the note outright, blank input
// normalises to NULL. mode='append': trimmed $text is concatenated
// onto the current note separated by a newline; appending onto a
// null/empty note is equivalent to set; a blank $text is a no-op.
/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
final class SetTransactionNote implements SetsTransactionNote
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
    ) {}

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

        // The stored note is ciphertext under an encrypted user, so the
        // append/change-guard must operate on the decrypted plaintext,
        // never the raw stored value.
        $currentNote = is_string($row->note)
            ? $this->codec->decryptValue('transactions', 'note', $row->note, $user->id, ($this->session)())['value']
            : null;
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
            ->update([
                'note' => is_string($target)
                    ? $this->codec->encryptValue('transactions', 'note', $target, $user->id, ($this->session)())
                    : $target,
            ]);
    }

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
