<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Services;

use Exception;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

/**
 * Read-side projection of pending_enrichment_conflicts. Used by the
 * first-conflict ReceiptConflictToast SFC to populate its body on
 * mount when no fresh ReceiptConflictDetected event has arrived this
 * request (covers queued-backfill flows where the conflict was held
 * during a background job and the user lands on the next page render).
 *
 * `latestForUser` returns the most-recently-created pending conflict
 * for the user, scoped by user_id — cross-user reads can never see a
 * foreign conflict.
 */
final readonly class ReceiptConflictQuery
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * @return array{transactionId: int, field: string, storedValue: ?string, incomingValue: ?string, sourceFormat: string}|null
     */
    public function latestForUser(User $user): ?array
    {
        $row = $this->db->connection()
            ->table('pending_enrichment_conflicts')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first(['transaction_id', 'field_name', 'stored_value', 'incoming_value', 'incoming_source_format']);

        if ($row === null) {
            return null;
        }

        return [
            'transactionId' => self::toInt($row->transaction_id),
            'field' => is_string($row->field_name) ? $row->field_name : '',
            'storedValue' => self::decodeScalar(is_string($row->stored_value) ? $row->stored_value : null),
            'incomingValue' => self::decodeScalar(is_string($row->incoming_value) ? $row->incoming_value : null),
            'sourceFormat' => is_string($row->incoming_source_format) ? $row->incoming_source_format : '',
        ];
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
        if ($decoded === null) {
            return null;
        }
        if (is_string($decoded)) {
            return $decoded;
        }
        if (is_scalar($decoded)) {
            return (string) $decoded;
        }

        return null;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
