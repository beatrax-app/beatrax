<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Services;

use Exception;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Import\Public\Enums\EnrichmentConflictField;

// Read-side projection of pending_enrichment_conflicts for the
// first-conflict toast's mount() fallback. Every read is scoped by
// user_id, so a foreign conflict can never surface.
final readonly class ReceiptConflictQuery
{
    use CoercesScalars;

    public function __construct(private DatabaseManager $db) {}

    /**
     * @return array{conflictId: int, transactionId: int, field: string, storedValue: ?string, incomingValue: ?string, sourceFormat: string, storedCurrency: string, incomingCurrency: string}|null
     */
    public function latestForUser(User $user): ?array
    {
        $row = $this->db->connection()
            ->table('pending_enrichment_conflicts as c')
            ->join('transactions as t', 't.id', '=', 'c.transaction_id')
            ->where('c.user_id', $user->id)
            ->orderByDesc('c.id')
            ->first([
                'c.id',
                'c.transaction_id',
                'c.field_name',
                'c.stored_value',
                'c.incoming_value',
                'c.incoming_source_format',
                't.currency as stored_currency',
            ]);

        if ($row === null) {
            return null;
        }

        $transactionId = self::toInt($row->transaction_id);
        $storedCurrency = self::toString($row->stored_currency);

        return [
            // The id, not just the transaction: the toast quotes ONE conflict's
            // two values and whatever the reader presses answers that one.
            'conflictId' => self::toInt($row->id),
            'transactionId' => $transactionId,
            'field' => is_string($row->field_name) ? $row->field_name : '',
            'storedValue' => self::decodeScalar(is_string($row->stored_value) ? $row->stored_value : null),
            'incomingValue' => self::decodeScalar(is_string($row->incoming_value) ? $row->incoming_value : null),
            'sourceFormat' => is_string($row->incoming_source_format) ? $row->incoming_source_format : '',
            'storedCurrency' => $storedCurrency,
            'incomingCurrency' => $this->incomingCurrencyFor($user, $transactionId) ?? $storedCurrency,
        ];
    }

    // A receipt that disagrees about the amount often disagrees about the
    // currency in the same breath, and those are two rows the reader answers
    // one at a time. Quoting the receipt's figure at the currency the
    // transaction still carries names the wrong money for it.
    private function incomingCurrencyFor(User $user, int $transactionId): ?string
    {
        $held = $this->db->connection()
            ->table('pending_enrichment_conflicts')
            ->where('user_id', $user->id)
            ->where('transaction_id', $transactionId)
            ->where('field_name', EnrichmentConflictField::Currency->value)
            ->value('incoming_value');

        $decoded = self::decodeScalar(is_string($held) ? $held : null);

        return $decoded === null || $decoded === '' ? null : $decoded;
    }

    private static function decodeScalar(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Exception) {
            return $raw;
        }

        return match (true) {
            is_string($decoded) => $decoded,
            is_scalar($decoded) => (string) $decoded,
            default => null,
        };
    }
}
