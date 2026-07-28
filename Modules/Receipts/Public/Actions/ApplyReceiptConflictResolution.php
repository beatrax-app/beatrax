<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// Persists the user's receipt_conflict_resolution policy and resolves
// every held pending_enrichment_conflicts row accordingly:
// prefer_receipt updates the transactions field then deletes the row;
// prefer_first_write keeps the stored value and just deletes the row.
/**
 * @link ../../../../.docs/features/receipts/architecture.md
 */
final class ApplyReceiptConflictResolution
{
    use CoercesScalars;

    private const ALLOWED_CHOICES = ['prefer_receipt', 'prefer_first_write'];

    // Mirrors the four field names FingerprintStage::detectConflicts
    // emits. Any other value on a stored row is rejected before it can
    // reach the SQL builder as a literal column name.
    private const ALLOWED_FIELDS = ['counterparty_name', 'description', 'currency', 'amount_minor'];

    // Mirrors SensitiveFieldRegistry — the two ALLOWED_FIELDS whose
    // incoming value must be encrypted before the update.
    /** @var list<string> */
    private const ENCRYPTED_FIELDS = ['counterparty_name', 'description'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
    ) {}

    public function __invoke(User $user, string $choice): int
    {
        if (! in_array($choice, self::ALLOWED_CHOICES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid receipt-conflict resolution choice %s — must be one of %s.',
                $choice,
                implode(', ', self::ALLOWED_CHOICES),
            ));
        }

        $now = $this->clock->now()->toDateTimeString();

        return $this->db->connection()->transaction(function () use ($user, $choice, $now): int {
            $this->db->connection()
                ->table('users')
                ->where('id', $user->id)
                ->update([
                    'receipt_conflict_resolution' => $choice,
                    'updated_at' => $now,
                ]);

            $rows = $this->db->connection()
                ->table('pending_enrichment_conflicts')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get();

            $resolved = 0;
            foreach ($rows as $row) {
                $this->resolveRow($row, $user, $choice, $now);
                $resolved++;
            }

            return $resolved;
        });
    }

    private function resolveRow(stdClass $row, User $user, string $choice, string $now): void
    {
        $transactionId = self::toInt($row->transaction_id);
        $fieldName = is_string($row->field_name) ? $row->field_name : '';
        $conflictId = self::toInt($row->id);

        $fieldIsAllowed = in_array($fieldName, self::ALLOWED_FIELDS, true);

        if ($choice === 'prefer_receipt' && $fieldIsAllowed) {
            $incomingRaw = is_string($row->incoming_value) ? $row->incoming_value : 'null';

            try {
                /** @var mixed $incoming */
                $incoming = json_decode($incomingRaw, associative: true, flags: JSON_THROW_ON_ERROR);

                // Encrypt the incoming plaintext for an encrypted user
                // (mirrors TagTransaction); a non-string decoded value
                // is left untouched, and this no-ops for a
                // non-encrypted user.
                if (is_string($incoming) && in_array($fieldName, self::ENCRYPTED_FIELDS, true)) {
                    $incoming = $this->codec->encryptValue('transactions', $fieldName, $incoming, $user->id, ($this->session)());
                }

                $this->db->connection()
                    ->table('transactions')
                    ->where('id', $transactionId)
                    ->where('user_id', $user->id)
                    ->update([
                        $fieldName => $incoming,
                        'updated_at' => $now,
                    ]);
            } catch (JsonException) {
                // A malformed stored value must not 500 the request —
                // skip the apply and fall through to delete the
                // pending row so corruption can never block future
                // conflicts.
            }
        }

        // Always delete the pending row, even when field_name fell
        // outside the whitelist — a corrupted row must not block
        // future conflicts on the same user.
        $this->db->connection()
            ->table('pending_enrichment_conflicts')
            ->where('id', $conflictId)
            ->where('user_id', $user->id)
            ->delete();
    }
}
