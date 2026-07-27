<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;
use stdClass;

/**
 * @link ../../../../.docs/features/transfers/architecture.md
 */
final class TransferPairer implements PairsTransferLegs
{
    private const WINDOW_DAYS = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly ResolvesKnownCounterpartyIban $aliasResolver,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
        private readonly EncryptionMigrationService $encryptionService,
    ) {}

    public function pairOne(Transaction $tx, User $user): ?int
    {
        if (! in_array($tx->type, ['transfer_out', 'transfer_in'], true)) {
            return null;
        }

        // Defensive: skip rows already paired (re-fire after pairing is
        // a no-op).
        if ($tx->pair_transaction_id !== null) {
            return null;
        }

        $connection = $this->db->connection();

        // Normalise to whole-day boundaries so the window is symmetric
        // ±WINDOW_DAYS calendar days regardless of the row's time-of-day
        // (different adapters book at different times: ASN at 12:00:00,
        // PayPal at startOfDay, etc.).
        $windowStart = $tx->booked_at->copy()->startOfDay()->subDays(self::WINDOW_DAYS);
        $windowEnd = $tx->booked_at->copy()->endOfDay()->addDays(self::WINDOW_DAYS);

        if ($tx->counterparty_iban === null || $tx->counterparty_iban === '') {
            $partnerRow = $this->findPartnerByReverseLookup(
                $tx,
                $user->id,
                $windowStart->toDateTimeString(),
                $windowEnd->toDateTimeString(),
            );
        } else {
            // Forward direction: Arm A (literal) matches one of the user's
            // own Account.iban rows directly; Arm B (alias bridge) resolves
            // real institution IBANs via ResolvesKnownCounterpartyIban.
            // Decrypt the ciphertext IBAN once before either arm runs.
            $ibanResult = $this->codec->decryptValue(
                'transactions',
                'counterparty_iban',
                $tx->counterparty_iban,
                $user->id,
                ($this->session)(),
            );

            // decryptValue never throws — on failure it returns the raw
            // ciphertext with decrypted:false. Only distrust that when
            // encryption is actually enabled for the user; otherwise
            // decrypted:false is the expected pass-through signal.
            if ($this->encryptionService->isEnabled($user->id) && ! $ibanResult['decrypted']) {
                return null;
            }
            $plainIban = $ibanResult['value'];

            $partnerAccountRow = $connection
                ->table('accounts')
                ->where('user_id', $user->id)
                ->where('iban', $plainIban)
                ->first(['id']);

            if ($partnerAccountRow !== null) {
                $partnerAccountId = self::toInt($partnerAccountRow->id ?? null);
            } else {
                $aliasAccount = $this->aliasResolver->resolveAccount($plainIban, $user->id);
                if ($aliasAccount === null) {
                    return null;
                }
                $partnerAccountId = $aliasAccount->id;
            }

            // Uses the partial index transactions_unpaired_transfer_idx;
            // the id != $tx->id clause guards the degenerate case where a
            // row's counterparty IBAN matches its own account's IBAN.
            $partnerRow = $connection
                ->table('transactions')
                ->where('user_id', $user->id)
                ->where('account_id', $partnerAccountId)
                ->where('amount_minor', -$tx->amount_minor)
                ->where('currency', $tx->currency)
                ->whereBetween('booked_at', [
                    $windowStart->toDateTimeString(),
                    $windowEnd->toDateTimeString(),
                ])
                ->whereNull('pair_transaction_id')
                ->whereIn('type', ['transfer_out', 'transfer_in'])
                ->where('id', '!=', $tx->id)
                ->orderBy('booked_at')
                ->first(['id']);
        }

        if ($partnerRow === null) {
            return null;
        }
        $partnerId = self::toInt($partnerRow->id ?? null);

        // Symmetric write: both sides get pair_transaction_id set. Outside
        // the listener's transaction frame the two updates are independent
        // statements — safe because ShouldBeUniqueUntilProcessing rules out
        // a concurrent run for the same user.
        $now = $this->clock->now()->toDateTimeString();

        $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('id', $tx->id)
            ->update([
                'pair_transaction_id' => $partnerId,
                'updated_at' => $now,
            ]);
        $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('id', $partnerId)
            ->update([
                'pair_transaction_id' => $tx->id,
                'updated_at' => $now,
            ]);

        // Keep the caller-supplied in-memory model in sync with the
        // persisted change so downstream observers see the post-pair state.
        $tx->pair_transaction_id = $partnerId;
        $tx->syncOriginalAttribute('pair_transaction_id');

        return $partnerId;
    }

    public function pairOrphansForUser(User $user): int
    {
        $connection = $this->db->connection();

        // Snapshot the candidate ids once; iterating an updating result set
        // is undefined under SQLite.
        /** @var list<int<1, max>> $candidateIds */
        $candidateIds = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', ['transfer_out', 'transfer_in'])
            ->whereNull('pair_transaction_id')
            ->orderBy('booked_at')
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $paired = 0;
        foreach ($candidateIds as $id) {
            /** @var Transaction|null $tx */
            $tx = Transaction::query()->find($id);
            if ($tx === null) {
                continue;
            }
            // The matcher may have written to BOTH rows during a
            // previous iteration; re-check before re-issuing the lookup.
            if ($tx->pair_transaction_id !== null) {
                continue;
            }
            if ($this->pairOne($tx, $user) !== null) {
                $paired++;
            }
        }

        return $paired;
    }

    /**
     * @link ../../../../.docs/features/transfers/architecture.md
     */
    private function findPartnerByReverseLookup(
        Transaction $tx,
        int $userId,
        string $windowStart,
        string $windowEnd,
    ): ?stdClass {
        $connection = $this->db->connection();

        $accountRow = $connection
            ->table('accounts')
            ->where('user_id', $userId)
            ->where('id', $tx->account_id)
            ->first(['iban', 'kind']);

        if ($accountRow === null) {
            return null;
        }

        $candidateIbans = [];

        $ownIban = self::toStringOrNull($accountRow->iban ?? null);
        if ($ownIban !== null && $ownIban !== '') {
            $candidateIbans[] = $ownIban;
        }

        $ownKind = self::toStringOrNull($accountRow->kind ?? null);
        if ($ownKind !== null && $ownKind !== '') {
            $aliasIbans = $connection
                ->table('known_counterparty_ibans')
                ->where('user_id', $userId)
                ->where('target_account_kind', $ownKind)
                ->pluck('real_iban')
                ->all();
            foreach ($aliasIbans as $aliasIban) {
                $aliasIbanStr = self::toStringOrNull($aliasIban);
                if ($aliasIbanStr !== null && $aliasIbanStr !== '') {
                    $candidateIbans[] = $aliasIbanStr;
                }
            }
        }

        if ($candidateIbans === []) {
            return null;
        }

        // counterparty_iban is ciphertext under encryption, so it can't be
        // a whereIn predicate; the narrowing predicates below already yield
        // a small candidate set, then each surviving row is decrypted and
        // matched against $candidateIbans in PHP.
        $candidates = $connection
            ->table('transactions')
            ->where('user_id', $userId)
            ->where('account_id', '!=', $tx->account_id)
            ->where('amount_minor', -$tx->amount_minor)
            ->where('currency', $tx->currency)
            ->whereBetween('booked_at', [$windowStart, $windowEnd])
            ->whereNull('pair_transaction_id')
            ->whereIn('type', ['transfer_out', 'transfer_in'])
            ->where('id', '!=', $tx->id)
            ->orderBy('booked_at')
            ->get(['id', 'counterparty_iban']);

        $encryptionEnabled = $this->encryptionService->isEnabled($userId);

        foreach ($candidates as $candidate) {
            /** @var stdClass $candidate */
            $storedCandidateIban = $candidate->counterparty_iban ?? null;
            if (! is_string($storedCandidateIban) || $storedCandidateIban === '') {
                continue;
            }
            $candidateResult = $this->codec->decryptValue(
                'transactions',
                'counterparty_iban',
                $storedCandidateIban,
                $userId,
                ($this->session)(),
            );

            // Skip a candidate whose IBAN failed to decrypt rather than
            // comparing $candidateIbans against ciphertext.
            if ($encryptionEnabled && ! $candidateResult['decrypted']) {
                continue;
            }

            if (in_array($candidateResult['value'], $candidateIbans, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
