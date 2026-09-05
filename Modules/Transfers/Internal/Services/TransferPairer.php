<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;
use Modules\Transfers\Public\Enums\CounterLegOrder;
use Modules\Transfers\Public\Services\PairLookup;
use Modules\Transfers\Public\Support\CounterLegMatch;
use Modules\Transfers\Public\Support\CounterLegWindow;
use stdClass;

final readonly class TransferPairer implements PairsTransferLegs
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private PairLinkWriter $links,
        private ResolvesKnownCounterpartyIban $aliasResolver,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private EncryptionMigrationService $encryptionService,
        private PairLookup $pairs,
    ) {}

    public function pairOne(Transaction $tx, User $user): ?int
    {
        if (! in_array($tx->type, TransactionType::transferValues(), true) || $tx->pair_transaction_id !== null) {
            return null;
        }

        $partnerId = ($tx->counterparty_iban === null || $tx->counterparty_iban === '')
            ? $this->findPartnerByReverseLookup($tx, $user->id)
            : $this->findPartnerForward($tx, $user);

        if ($partnerId === null) {
            return null;
        }

        $this->linkPair($tx, $user, $partnerId);

        return $partnerId;
    }

    // Two arms: a literal match against the user's own Account.iban rows, and
    // an alias bridge resolving institution IBANs. The ciphertext IBAN is
    // decrypted once, before either.
    private function findPartnerForward(Transaction $tx, User $user): ?int
    {
        $ibanResult = $this->codec->decryptValue(
            'transactions',
            'counterparty_iban',
            (string) $tx->counterparty_iban,
            $user->id,
            ($this->session)(),
        );

        // decryptValue never throws; it returns the raw ciphertext with
        // decrypted:false, which is also the expected pass-through signal
        // when encryption is off. Only distrust it when encryption is on.
        if ($this->encryptionService->isEnabled($user->id) && ! $ibanResult['decrypted']) {
            return null;
        }

        $partnerAccountId = $this->resolvePartnerAccountId($ibanResult['value'], $user, $tx->account_id);
        if ($partnerAccountId === null) {
            return null;
        }

        // Still excluded by id as well: the account guard already keeps the row
        // out of its own result set, but passing null would say the caller
        // wants itself back.
        return $this->pairs->counterLegOnAccount(
            new CounterLegMatch(
                accountId: $partnerAccountId,
                amountMinor: -$tx->amount_minor,
                types: self::transferTypes(),
                currency: $tx->currency,
                unpairedOnly: true,
                excludeTransactionId: $tx->id,
            ),
            new CounterLegWindow($tx->booked_at, CounterLegWindow::DEFAULT_DAYS, CounterLegOrder::EarliestBooked),
            $user,
        );
    }

    /**
     * @return list<TransactionType>
     */
    private static function transferTypes(): array
    {
        return array_map(
            static fn (string $value): TransactionType => TransactionType::from($value),
            TransactionType::transferValues(),
        );
    }

    // Both arms refuse the account the leg already sits on — a transfer
    // crosses two accounts. resolveAccount() answers with the LOWEST-id
    // account of the aliased kind, which is the firing leg's own whenever it
    // sits there: two ICS cards, and the row on the first pairs beside itself.
    private function resolvePartnerAccountId(string $plainIban, User $user, int $ownAccountId): ?int
    {
        $partnerAccountRow = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', '!=', $ownAccountId)
            ->where('iban', $plainIban)
            ->first(['id']);

        if ($partnerAccountRow !== null) {
            return self::toInt($partnerAccountRow->id ?? null);
        }

        $aliasAccountId = $this->aliasResolver->resolveAccount($plainIban, $user->id)?->id;

        return $aliasAccountId === $ownAccountId ? null : $aliasAccountId;
    }

    private function linkPair(Transaction $tx, User $user, int $partnerId): void
    {
        $this->links->link($user->id, $tx->id, $partnerId);

        // The row was written through the query builder, so the caller's model is
        // re-synced by hand and observers see the post-pair state.
        $tx->pair_transaction_id = $partnerId;
        $tx->syncOriginalAttribute('pair_transaction_id');
    }

    public function pairOrphansForUser(User $user): int
    {
        $connection = $this->db->connection();

        // The sweep order decides which orphan asks first, and pairOne()
        // persists that answer — the reason counterLegOnAccount() and the
        // reverse arm both end on id. Orphans routinely share a booked_at
        // (ASN books every row at 12:00:00), leaving the winner to SQLite.
        /** @var list<int<1, max>> $candidateIds */
        $candidateIds = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', TransactionType::transferValues())
            ->whereNull('pair_transaction_id')
            ->orderBy('booked_at')
            ->orderBy('id')
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
            if ($tx->pair_transaction_id !== null) {
                continue;
            }
            if ($this->pairOne($tx, $user) !== null) {
                $paired++;
            }
        }

        return $paired;
    }

    private function findPartnerByReverseLookup(Transaction $tx, int $userId): ?int
    {
        $connection = $this->db->connection();

        // Whole-day boundaries keep the window symmetric in calendar days;
        // adapters book at different times (ASN at 12:00:00, PayPal at
        // startOfDay), which otherwise skews it.
        $windowStart = $tx->booked_at->copy()->startOfDay()->subDays(CounterLegWindow::DEFAULT_DAYS)->toDateTimeString();
        $windowEnd = $tx->booked_at->copy()->endOfDay()->addDays(CounterLegWindow::DEFAULT_DAYS)->toDateTimeString();

        $accountRow = $connection
            ->table('accounts')
            ->where('user_id', $userId)
            ->where('id', $tx->account_id)
            ->first(['iban', 'kind']);

        if ($accountRow === null) {
            return null;
        }

        $candidateIbans = $this->reverseLookupCandidateIbans($accountRow, $userId);
        if ($candidateIbans === []) {
            return null;
        }

        // counterparty_iban is ciphertext, so it cannot be a whereIn. The
        // predicates below narrow to a small set, which is then decrypted
        // and matched in PHP.
        $candidates = $connection
            ->table('transactions')
            ->where('user_id', $userId)
            ->where('account_id', '!=', $tx->account_id)
            ->where('amount_minor', -$tx->amount_minor)
            ->where('currency', $tx->currency)
            ->whereBetween('booked_at', [$windowStart, $windowEnd])
            ->whereNull('pair_transaction_id')
            ->whereIn('type', TransactionType::transferValues())
            ->where('id', '!=', $tx->id)
            // Two legs of one transfer routinely book on the same day, and the
            // first row that decrypts to a match is the one written into
            // pair_transaction_id. Ordering on booked_at alone left which of
            // them that is to the engine, and it persists the answer.
            ->orderBy('booked_at')
            ->orderBy('id')
            ->get(['id', 'counterparty_iban']);

        $match = $this->matchDecryptedCandidate($candidates, $candidateIbans, $userId);

        return $match === null ? null : self::toInt($match->id ?? null);
    }

    /**
     * @return list<string>
     */
    private function reverseLookupCandidateIbans(stdClass $accountRow, int $userId): array
    {
        $candidateIbans = [];

        $ownIban = self::toStringOrNull($accountRow->iban ?? null);
        if ($ownIban !== null && $ownIban !== '') {
            $candidateIbans[] = $ownIban;
        }

        $ownKind = self::toStringOrNull($accountRow->kind ?? null);
        if ($ownKind === null || $ownKind === '') {
            return $candidateIbans;
        }

        $aliasIbans = $this->db->connection()
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

        return $candidateIbans;
    }

    /**
     * @param  iterable<mixed>  $candidates
     * @param  list<string>  $candidateIbans
     */
    private function matchDecryptedCandidate(iterable $candidates, array $candidateIbans, int $userId): ?stdClass
    {
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

            if ($encryptionEnabled && ! $candidateResult['decrypted']) {
                continue;
            }

            if (in_array($candidateResult['value'], $candidateIbans, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
