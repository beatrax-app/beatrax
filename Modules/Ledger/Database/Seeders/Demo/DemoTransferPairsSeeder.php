<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Exceptions\IdReadBackFailedException;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;

// One explicit reimbursement pair, distinct from DemoTransactionsSeeder's
// monthly chain-flow transfers; idempotent via its own demo ImportRun.
final class DemoTransferPairsSeeder
{
    // The day inside the seeded window this pair posts on. It is placed by
    // naming a period the budgets grid draws, not by counting days back from
    // today: this is a source_format='demo' row like every other, and only the
    // window says which periods the grid can navigate to.
    private const PAIR_DAY_OF_MONTH = 11;

    private const PAIR_AMOUNT_MINOR = 4500;

    public function __construct(
        private readonly FingerprintComposer $fingerprints,
        private readonly CounterpartyKey $counterpartyKey,
        private readonly DemoPeriodWindow $window,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, User>  $users
     * @param  array<string, array<string, Account>>  $accounts
     */
    public function run(array $users, array $accounts): int
    {
        $primary = $users['demo-1'] ?? null;
        $userAccounts = $accounts['demo-1'] ?? null;

        if ($primary === null || $userAccounts === null) {
            return 0;
        }
        if (! isset($userAccounts['asn-demo-1'], $userAccounts['paypal-demo-1'])) {
            return 0;
        }

        $asn = $userAccounts['asn-demo-1'];
        $paypal = $userAccounts['paypal-demo-1'];

        $now = $this->clock->now();
        $periods = $this->window->forUser($primary, $now);
        $middlePeriod = $periods[intdiv(count($periods), 2)];
        $pairDate = DemoPeriodWindow::dayIn($middlePeriod, self::PAIR_DAY_OF_MONTH);

        $asnRun = $this->ensureImportRun($primary, $asn, $now);
        $paypalRun = $this->ensureImportRun($primary, $paypal, $now);

        $this->insertLeg($primary, $asn, $asnRun, new DemoTransferLeg(
            type: TransactionType::TransferOut,
            amountMinor: -self::PAIR_AMOUNT_MINOR,
            description: 'Tikkie reimbursement to PayPal',
            sourceRef: 'DEMO-PAIR-OUT-1',
        ), $pairDate, $now);
        $this->insertLeg($primary, $paypal, $paypalRun, new DemoTransferLeg(
            type: TransactionType::TransferIn,
            amountMinor: self::PAIR_AMOUNT_MINOR,
            description: 'Tikkie reimbursement from ASN',
            sourceRef: 'DEMO-PAIR-IN-1',
        ), $pairDate, $now);

        $this->linkPair($primary, $pairDate);

        return Transaction::query()
            ->where('user_id', $primary->id)
            ->where('source_format', 'demo')
            ->whereIn('source_ref', ['DEMO-PAIR-OUT-1', 'DEMO-PAIR-IN-1'])
            ->count();
    }

    private function ensureImportRun(User $user, Account $account, CarbonImmutable $now): ImportRun
    {
        $sha = hash('sha256', 'demo-pair|'.$user->username.'|'.$account->slug);

        ImportRun::query()->updateOrCreate(
            ['user_id' => $user->id, 'sha256' => $sha],
            [
                'source_format' => 'demo',
                'raw_file_path' => 'demo://transfer-pair/'.$account->slug,
                'uploaded_at' => $now->startOfDay(),
                'confirmed_at' => $now->startOfDay(),
                'status' => ImportRunStatus::Confirmed->value,
            ],
        );

        // Re-read by the same UNIQUE rather than kept from updateOrCreate(): it
        // ends in insertGetId(), lastInsertId() is per connection, and the badge
        // listener writes a `cache` row from inside this INSERT's own event, so
        // both legs would name a run that does not exist.
        return ImportRun::query()
            ->where('user_id', $user->id)
            ->where('sha256', $sha)
            ->first() ?? throw new IdReadBackFailedException('import_runs');
    }

    private function insertLeg(
        User $user,
        Account $account,
        ImportRun $run,
        DemoTransferLeg $leg,
        CarbonImmutable $date,
        CarbonImmutable $now,
    ): void {
        $normalized = $this->counterpartyKey->forName($leg->description, $user->id);
        $bookedAt = $date->setTime(12, 0, 0);
        $isOut = $leg->type === TransactionType::TransferOut;

        $canonical = new CanonicalTransaction(
            userId: $user->id,
            accountId: $account->id,
            type: $leg->type->value,
            postedAt: $date,
            bookedAt: $bookedAt,
            valueDate: $date,
            amountMinor: $leg->amountMinor,
            currency: Currency::Eur->value,
            settledAmountMinor: $leg->amountMinor,
            settledCurrency: Currency::Eur->value,
            counterpartyName: $isOut ? 'PayPal' : 'ASN Bank',
            counterpartyIban: $isOut ? 'PAYPAL-DEMO-1' : 'NL57ASNB0123456789',
            counterpartyNormalized: $normalized,
            normalizationVersion: $this->fingerprints->version(),
            description: $leg->description,
            categoryId: null,
            sourceFormat: 'demo',
            importRunId: $run->id,
            sourceRowIndex: 0,
            sourceRef: $leg->sourceRef,
            rawPayload: null,
            autoCategoryProvenance: null,
            paymentType: PaymentType::Transfer,
        );

        $fingerprint = $this->fingerprints->compose($canonical);
        $stamp = $now->toDateTimeString();

        $attrs = array_merge($canonical->toAttributes(), [
            'fingerprint' => $fingerprint,
            'fingerprint_version' => $this->fingerprints->version(),
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        Transaction::query()->insertOrIgnore($attrs);
    }

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
