<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use JsonException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Import\Public\Enums\EnrichmentConflictField;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Ledger\Public\ValueObjects\TransactionAmount;
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;

// Persists the user's receipt_conflict_resolution policy and resolves the ONE
// held pending_enrichment_conflicts row the reader answered: prefer_receipt
// updates the transactions field then deletes the row; prefer_first_write keeps
// the stored value and just deletes the row.
final readonly class ApplyReceiptConflictResolution
{
    use CoercesScalars;

    // Read under the row lock so the recompose has every term of the tuple, and
    // the amount identity every column of itself, without a second SELECT
    // outside it.
    /** @var list<string> */
    private const array LOCKED_ROW_COLUMNS = [
        'id',
        'status',
        'account_id',
        'posted_at',
        'booked_at',
        'amount_minor',
        'currency',
        'settled_amount_minor',
        'settled_currency',
        'fx_rate_used',
        'counterparty_normalized',
    ];

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private FingerprintComposer $fingerprints,
        private CounterpartyKey $counterpartyKey,
        private Dispatcher $events,
        private LoggerInterface $logger,
    ) {}

    // One conflict per call, because the toast names one conflict and quotes
    // its two values: a reader consenting to that change must not get every
    // other outstanding change with it. The policy write is what answers the
    // copy's "for future conflicts" question.
    public function __invoke(User $user, ReceiptConflictChoice $choice, int $conflictId): int
    {
        $now = $this->clock->now()->toDateTimeString();

        /** @var array<string, mixed> $mutation */
        $mutation = [];
        $transactionId = 0;

        $resolved = $this->db->connection()->transaction(function () use ($user, $choice, $conflictId, $now, &$mutation, &$transactionId): int {
            $this->db->connection()
                ->table('users')
                ->where('id', $user->id)
                ->update([
                    'receipt_conflict_resolution' => $choice->value,
                    'updated_at' => $now,
                ]);

            $row = $this->db->connection()
                ->table('pending_enrichment_conflicts')
                ->where('id', $conflictId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return 0;
            }

            $transactionId = self::toInt($row->transaction_id);
            $mutation = $this->resolveRow($row, $user, $choice, $now);

            return 1;
        });

        // After the commit, never inside it: the listener writes the op-log the
        // paired device replays, and a rollback cannot reach it there. Without
        // this the peer keeps the pre-resolution value AND the old fingerprint,
        // so re-importing the same statement lands a duplicate row.
        if ($mutation !== []) {
            $this->events->dispatch(new TransactionMutated(
                transactionId: $transactionId,
                userId: $user->id,
                mutationType: 'edit',
                dirtyFields: $mutation,
            ));
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed> the plaintext columns written, empty when nothing was
     */
    private function resolveRow(stdClass $row, User $user, ReceiptConflictChoice $choice, string $now): array
    {
        $transactionId = self::toInt($row->transaction_id);
        $conflictId = self::toInt($row->id);
        $field = EnrichmentConflictField::tryFrom(is_string($row->field_name) ? $row->field_name : '');
        $written = [];

        if ($choice === ReceiptConflictChoice::PreferReceipt && $field !== null) {
            $incomingRaw = is_string($row->incoming_value) ? $row->incoming_value : 'null';

            try {
                /** @var mixed $incoming */
                $incoming = json_decode($incomingRaw, associative: true, flags: JSON_THROW_ON_ERROR);
                $written = $this->applyIncoming($transactionId, $field, $incoming, $user, $now);
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

        return $written;
    }

    /**
     * @return array<string, mixed>
     */
    private function applyIncoming(int $transactionId, EnrichmentConflictField $field, mixed $incoming, User $user, string $now): array
    {
        $txRow = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first(self::LOCKED_ROW_COLUMNS);

        // A reconcile freezes the row, so the frozen value stands whatever the
        // policy says — and the pending row still clears in resolveRow(), or a
        // conflict no policy can ever resolve would raise the toast on every
        // render with nothing the reader could press to be rid of it.
        $frozen = $txRow !== null && TransactionStatusQuery::locksEdits($txRow->status);

        if ($frozen) {
            $this->logger->debug('Receipt conflict cleared without a write: the transaction is reconciled', [
                'transaction_id' => $transactionId,
                'field' => $field->value,
            ]);
        }

        if ($txRow === null || $frozen) {
            return [];
        }

        $amount = self::resolvedAmount($txRow, $field, $incoming);

        // Derived from the plaintext, before encryptValue seals the name: the
        // counterparty key digests the normalised name, and AEAD ciphertext
        // differs on every write of the same value.
        $rederived = $this->rederivedFingerprint($txRow, $field, $incoming, $amount, $user);

        // Mirrors TagTransaction; a non-string decoded value is left untouched,
        // and this no-ops for a non-encrypted user.
        $sealed = is_string($incoming) && $this->codec->isEncrypted('transactions', $field->value)
            ? $this->codec->encryptValue('transactions', $field->value, $incoming, $user->id, ($this->session)())
            : $incoming;

        $amountColumns = $amount?->toColumns();
        $plaintext = $amountColumns ?? [$field->value => $incoming];
        $written = $amountColumns ?? [$field->value => $sealed];

        try {
            $this->db->connection()
                ->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->update($rederived + $written + ['updated_at' => $now]);
        } catch (UniqueConstraintViolationException) {
            // The resolved row would be indistinguishable from one the ledger
            // already holds, which is an answer and not a failure: this receipt
            // describes that transaction. The stored row stands, and the
            // conflict still clears in resolveRow().
            $this->logger->warning('Receipt conflict resolved onto a transaction the ledger already holds; the stored row stands', [
                'transaction_id' => $transactionId,
                'field' => $field->value,
            ]);

            return [];
        }

        // Plaintext, and the recomposed tuple with it: OpLogWriter seals a
        // sensitive value itself, and a peer replaying only the field would
        // hold the resolved value under the fingerprint it no longer matches.
        return $rederived + $plaintext;
    }

    // Null for a field that is not an amount. The amount columns are one value,
    // so the write that moves any of them moves all of them.
    private static function resolvedAmount(stdClass $txRow, EnrichmentConflictField $field, mixed $incoming): ?TransactionAmount
    {
        $stored = new TransactionAmount(
            self::toInt($txRow->amount_minor),
            self::toString($txRow->currency),
            self::toInt($txRow->settled_amount_minor),
            self::toString($txRow->settled_currency),
            self::toStringOrNull($txRow->fx_rate_used),
        );

        return match ($field) {
            EnrichmentConflictField::AmountMinor => $stored->withAmountMinor(self::toInt($incoming)),
            EnrichmentConflictField::Currency => $stored->withCurrency(self::toString($incoming)),
            default => null,
        };
    }

    // The fingerprint is the dedup key and is composed OVER these columns, so
    // it travels in the statement that rewrites them or the row stops matching
    // its own re-import and lands twice.
    /**
     * @return array<string, mixed>
     */
    private function rederivedFingerprint(
        stdClass $txRow,
        EnrichmentConflictField $field,
        mixed $incoming,
        ?TransactionAmount $amount,
        User $user,
    ): array {
        if (! $field->isFingerprintInput()) {
            return [];
        }

        $rederived = [];
        $normalized = self::toString($txRow->counterparty_normalized);

        if ($field === EnrichmentConflictField::CounterpartyName) {
            $normalized = $this->counterpartyKey->forName(self::toStringOrNull($incoming), $user->id);
            $rederived['counterparty_normalized'] = $normalized;
            $rederived['normalization_version'] = $this->fingerprints->version();
        }

        $rederived['fingerprint'] = $this->fingerprints->composeTuple(
            $user->id,
            self::toInt($txRow->account_id),
            CarbonImmutable::parse(self::toString($txRow->posted_at))->toDateString(),
            CarbonImmutable::parse(self::toString($txRow->booked_at))->toDateTimeString(),
            $amount->amountMinor ?? self::toInt($txRow->amount_minor),
            $amount->currency ?? self::toString($txRow->currency),
            $normalized,
        );
        $rederived['fingerprint_version'] = $this->fingerprints->version();

        return $rederived;
    }
}
