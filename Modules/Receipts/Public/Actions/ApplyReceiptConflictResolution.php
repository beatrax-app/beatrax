<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Actions;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Persists the per-user `receipt_conflict_resolution` policy +
 * resolves every held `pending_enrichment_conflicts` row for the
 * user according to the chosen policy.
 *
 * Two valid choices (enforced via the `ALLOWED_CHOICES` whitelist on
 * every __invoke; an out-of-list value raises InvalidArgumentException
 * before any DB read):
 *
 *  - `prefer_receipt` — UPDATE transactions.{field_name} with the
 *    receipt's incoming value for each held conflict; then DELETE
 *    the pending row.
 *  - `prefer_first_write` — keep the stored value verbatim; just
 *    DELETE the pending row.
 *
 * Both branches persist the chosen policy on users.receipt_conflict_resolution
 * so subsequent ApplyEnrichments calls apply the policy silently
 * without surfacing the toast again.
 *
 * Returns the number of pending conflicts resolved.
 *
 * Defence-in-depth on `field_name`: the per-row `field_name` (read
 * from `pending_enrichment_conflicts.field_name`) is whitelisted via
 * `ALLOWED_FIELDS` before being used as a literal SQL column name in
 * the `transactions` UPDATE. The upstream producer in
 * `FingerprintStage::detectConflicts` is hardcoded to the same four
 * field names — any row carrying a different value is treated as
 * corruption (or evidence of a future producer drift) and is deleted
 * without touching the transactions table.
 *
 * Cross-user safety: every read + write is scoped by
 * `where('user_id', $user->id)` so a foreign user's pending row is
 * never touched even if the action is invoked with a borrowed
 * conflict id. The action takes a `User`, not a raw conflict id, so
 * a caller cannot smuggle in another user's row by id.
 */
final class ApplyReceiptConflictResolution
{
    /** Allowed policy values — mirrors the DB enum trigger allow-list. */
    private const ALLOWED_CHOICES = ['prefer_receipt', 'prefer_first_write'];

    /**
     * Allowed `field_name` values. Mirrors the four field names emitted
     * by `FingerprintStage::detectConflicts` and the `transactions`
     * columns those names map to. Any other value received from a
     * stored `pending_enrichment_conflicts` row is rejected before it
     * can reach the SQL builder as a literal column name.
     */
    private const ALLOWED_FIELDS = ['counterparty_name', 'description', 'currency', 'amount_minor'];

    /**
     * Sensitive columns whose incoming value must be encrypted before
     * the `transactions` UPDATE (D-07). Mirrors the two field names in
     * `SensitiveFieldRegistry` out of `ALLOWED_FIELDS`'s four —
     * `currency`/`amount_minor` are never sensitive and pass through
     * unencrypted.
     *
     * @var list<string>
     */
    private const ENCRYPTED_FIELDS = ['counterparty_name', 'description'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
        private readonly LoggerInterface $logger,
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
            $incomingStored = is_string($row->incoming_value) ? $row->incoming_value : 'null';

            // CR-03/WR-19: incoming_value is persisted encrypted-at-rest by
            // ApplyEnrichments::holdConflicts() for an encrypted user. Decrypt
            // BEFORE json_decode. decryptValue never throws and is a
            // pass-through for legacy-plaintext / non-encryption rows.
            $incomingRaw = $this->codec->decryptValue(
                'pending_enrichment_conflicts',
                'incoming_value',
                $incomingStored,
                $user->id,
                $this->session,
            )['value'];

            try {
                /** @var mixed $incoming */
                $incoming = json_decode($incomingRaw, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                // WR-19: a decode failure here means the stored value could not
                // be decrypted (e.g. a rekey/epoch gap left it as ciphertext)
                // or is genuinely corrupt. Do NOT delete the pending row — the
                // old code silently deleted on JsonException, which (once
                // incoming_value is encrypted) SILENTLY DROPS the receipt's
                // resolution. Log and KEEP the row so it can be retried once the
                // covering epoch is available (or inspected). The transactions
                // table is left untouched.
                $this->logger->warning('Receipt conflict resolution skipped: incoming_value could not be decoded/decrypted — pending row preserved.', [
                    'user_id' => $user->id,
                    'conflict_id' => $conflictId,
                    'field' => $fieldName,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            // D-07 encrypt-incoming-before-update (14.1-12 Cluster 3 /
            // CR-01/CR-02 class): $incoming is a fresh plaintext value parsed
            // out of the (now-decrypted) held-conflict JSON — it must be
            // encrypted before landing in the transactions UPDATE for an
            // encrypted user, mirroring TagTransaction's encrypt-before-write.
            // Only the two sensitive columns are ever encrypted; a non-string
            // value (e.g. a decoded null) is left untouched. Pass-through no-op
            // for a non-encrypted user.
            if (is_string($incoming) && in_array($fieldName, self::ENCRYPTED_FIELDS, true)) {
                $incoming = $this->codec->encryptValue('transactions', $fieldName, $incoming, $user->id, $this->session);
            }

            $this->db->connection()
                ->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->update([
                    $fieldName => $incoming,
                    'updated_at' => $now,
                ]);
        }

        // Delete the pending row. Reached for prefer_first_write, a
        // non-whitelisted field_name (a corrupted row should not block future
        // conflicts — transactions is never mutated in that case), or a
        // successfully-applied prefer_receipt row. The WR-19 decrypt/decode
        // failure path above returns EARLY and never reaches here, so an
        // undecryptable resolution is preserved rather than silently dropped.
        $this->db->connection()
            ->table('pending_enrichment_conflicts')
            ->where('id', $conflictId)
            ->where('user_id', $user->id)
            ->delete();
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
