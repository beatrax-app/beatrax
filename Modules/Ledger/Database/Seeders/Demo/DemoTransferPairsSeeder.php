<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;

/**
 * Materialises one explicit cross-account transfer pair for the
 * primary demo user, distinct from the monthly chain-flow transfers
 * the DemoTransactionsSeeder already pairs via `pair_transaction_id`.
 *
 * The pair models a one-off "reimburse for shared dinner" event:
 *
 *   - `transfer_out` on demo-1's ASN bank account
 *   - `transfer_in` on demo-1's PayPal wallet
 *
 * Both legs land on the same date with the same minor amount and are
 * linked via `pair_transaction_id` after both rows are written,
 * mirroring the production Layer-1 pair-detection listener. The
 * resulting pair is rendered as a single connected event in the
 * transfer-pair UI element.
 *
 * Idempotency: both legs ride on a dedicated `source_format='demo'`
 * ImportRun whose sha256 is deterministic across runs, so a second
 * seed run finds the same rows via the `(user_id, fingerprint)`
 * UNIQUE on transactions and skips the insertOrIgnore. The pair link
 * is re-applied each run, but `update(['pair_transaction_id' => …])`
 * on an already-linked row is a no-op write.
 */
final class DemoTransferPairsSeeder
{
    private const PAIR_DAY_OFFSET = 50;

    private const PAIR_AMOUNT_MINOR = 4500;

    public function __construct(
        private readonly FingerprintComposer $fingerprints,
    ) {}

    /**
     * @param  array<string, User>  $users
     * @param  array<string, array<string, Account>>  $accounts
     */
    public function run(array $users, array $accounts): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        $userAccounts = $accounts['demo-1@beatrax.local'] ?? null;

        if ($primary === null || $userAccounts === null) {
            return 0;
        }
        if (! isset($userAccounts['asn-demo-1'], $userAccounts['paypal-demo-1'])) {
            return 0;
        }

        $asn = $userAccounts['asn-demo-1'];
        $paypal = $userAccounts['paypal-demo-1'];

        $today = CarbonImmutable::today();
        $pairDate = $today->subDays(self::PAIR_DAY_OFFSET);

        $asnRun = $this->ensureImportRun($primary, $asn);
        $paypalRun = $this->ensureImportRun($primary, $paypal);

        $this->insertLeg(
            $primary,
            $asn,
            $asnRun,
            type: 'transfer_out',
            amountMinor: -self::PAIR_AMOUNT_MINOR,
            description: 'Tikkie reimbursement to PayPal',
            sourceRef: 'DEMO-PAIR-OUT-1',
            date: $pairDate,
        );
        $this->insertLeg(
            $primary,
            $paypal,
            $paypalRun,
            type: 'transfer_in',
            amountMinor: self::PAIR_AMOUNT_MINOR,
            description: 'Tikkie reimbursement from ASN',
            sourceRef: 'DEMO-PAIR-IN-1',
            date: $pairDate,
        );

        $this->linkPair($primary, $pairDate);

        return Transaction::query()
            ->where('user_id', $primary->id)
            ->where('source_format', 'demo')
            ->whereIn('source_ref', ['DEMO-PAIR-OUT-1', 'DEMO-PAIR-IN-1'])
            ->count();
    }

    private function ensureImportRun(User $user, Account $account): ImportRun
    {
        $sha = hash('sha256', 'demo-pair|'.$user->username.'|'.$account->slug);

        /** @var ImportRun $run */
        $run = ImportRun::query()->updateOrCreate(
            ['user_id' => $user->id, 'sha256' => $sha],
            [
                'source_format' => 'demo',
                'raw_file_path' => 'demo://transfer-pair/'.$account->slug,
                'uploaded_at' => CarbonImmutable::today(),
                'confirmed_at' => CarbonImmutable::today(),
                'status' => 'confirmed',
            ],
        );

        return $run;
    }

    private function insertLeg(
        User $user,
        Account $account,
        ImportRun $run,
        string $type,
        int $amountMinor,
        string $description,
        string $sourceRef,
        CarbonImmutable $date,
    ): void {
        $normalized = $this->fingerprints->normalize($description);
        $bookedAt = $date->setTime(12, 0, 0);

        $canonical = new CanonicalTransaction(
            userId: $user->id,
            accountId: $account->id,
            type: $type,
            postedAt: $date,
            bookedAt: $bookedAt,
            valueDate: $date,
            amountMinor: $amountMinor,
            currency: 'EUR',
            settledAmountMinor: $amountMinor,
            settledCurrency: 'EUR',
            fxRateUsed: null,
            counterpartyName: $type === 'transfer_out' ? 'PayPal' : 'ASN Bank',
            counterpartyIban: $type === 'transfer_out' ? 'PAYPAL-DEMO-1' : 'NL57ASNB0123456789',
            counterpartyNormalized: $normalized,
            normalizationVersion: $this->fingerprints->version(),
            description: $description,
            categoryId: null,
            sourceFormat: 'demo',
            importRunId: $run->id,
            sourceRowIndex: 0,
            sourceRef: $sourceRef,
            rawPayload: null,
            autoCategoryProvenance: null,
            paymentType: PaymentType::Transfer,
        );

        $fingerprint = $this->fingerprints->compose($canonical);
        $now = CarbonImmutable::now()->toDateTimeString();

        $attrs = array_merge($canonical->toAttributes(), [
            'fingerprint' => $fingerprint,
            'fingerprint_version' => $this->fingerprints->version(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Transaction::query()->insertOrIgnore($attrs);
    }

    /**
     * Wire `pair_transaction_id` on both legs once both rows are
     * persisted. Idempotent: the production Layer-1 listener uses the
     * same identity-key shape and a second invocation on an already-
     * linked pair is a no-op write.
     */
    private function linkPair(User $user, CarbonImmutable $pairDate): void
    {
        $out = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('source_ref', 'DEMO-PAIR-OUT-1')
            ->whereDate('posted_at', $pairDate->toDateString())
            ->first();
        $in = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('source_ref', 'DEMO-PAIR-IN-1')
            ->whereDate('posted_at', $pairDate->toDateString())
            ->first();

        if ($out === null || $in === null) {
            return;
        }

        Transaction::query()->where('id', $out->id)->update(['pair_transaction_id' => $in->id]);
        Transaction::query()->where('id', $in->id)->update(['pair_transaction_id' => $out->id]);
    }
}
