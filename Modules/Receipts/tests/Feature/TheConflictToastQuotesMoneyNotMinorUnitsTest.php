<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Receipts\Internal\Http\Livewire\ReceiptConflictToast;

// The toast quotes the two disagreeing values inside its own sentence, and for
// an amount conflict those values are the stored integers. It read "an email
// receipt records a different amount (“-3199”) than the statement (“-3200”)" —
// a count of minor units offered to the reader as money, and nothing on screen
// said which currency either figure was in.

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureAccount = $seeded['account'];
    $this->actingAs($this->fixtureUser);
});

function seedAmountConflict(User $user, Account $account, string $currency, int $stored, int $incoming): int
{
    static $idx = 0;
    $idx++;

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/amount-conflict-'.$idx.'.dat',
        'sha256' => str_pad((string) $idx, 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    $tx = Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-04-01',
        'booked_at' => '2026-04-01 12:00:'.str_pad((string) $idx, 2, '0', STR_PAD_LEFT),
        'value_date' => '2026-04-01',
        'amount_minor' => $stored,
        'currency' => $currency,
        'settled_amount_minor' => $stored,
        'settled_currency' => $currency,
        'counterparty_name' => 'Fixture Merchant',
        'counterparty_normalized' => 'fixture merchant',
        'normalization_version' => 1,
        'source_format' => 'paypal-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $idx,
        'fingerprint' => str_pad((string) $idx, 64, 'a', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    DB::table('pending_enrichment_conflicts')->insert([
        'user_id' => $user->id,
        'transaction_id' => $tx->id,
        'field_name' => 'amount_minor',
        'stored_value' => json_encode($stored),
        'incoming_value' => json_encode($incoming),
        'incoming_source_format' => 'paypal-receipt',
        'import_run_id' => $run->id,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return (int) $tx->id;
}

it('quotes a euro conflict as money the reader can read, not as its minor units', function (): void {
    seedAmountConflict($this->fixtureUser, $this->fixtureAccount, Currency::Eur->value, -3200, -3199);

    Livewire::test(ReceiptConflictToast::class)
        ->assertSee(Money::ofMinor(-3199, Currency::Eur->value)->format())
        ->assertSee(Money::ofMinor(-3200, Currency::Eur->value)->format())
        ->assertDontSee('-3199')
        ->assertDontSee('-3200');
});

it('quotes a yen conflict at the scale a yen has, never at a hundredth of it', function (): void {
    seedAmountConflict($this->fixtureUser, $this->fixtureAccount, Currency::Jpy->value, -1300, -1250);

    Livewire::test(ReceiptConflictToast::class)
        ->assertSee(Money::ofMinor(-1250, Currency::Jpy->value)->format())
        ->assertDontSee('12.50')
        ->assertDontSee('-1250');
});

it('quotes the receipt figure in the currency the receipt named, not the stored one', function (): void {
    $txId = seedAmountConflict($this->fixtureUser, $this->fixtureAccount, Currency::Eur->value, -3200, -3199);

    DB::table('pending_enrichment_conflicts')->insert([
        'user_id' => $this->fixtureUser->id,
        'transaction_id' => $txId,
        'field_name' => 'currency',
        'stored_value' => json_encode(Currency::Eur->value),
        'incoming_value' => json_encode(Currency::Usd->value),
        'incoming_source_format' => 'paypal-receipt',
        'import_run_id' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    DB::table('pending_enrichment_conflicts')
        ->where('transaction_id', $txId)
        ->where('field_name', 'amount_minor')
        ->update(['id' => 9_000]);

    Livewire::test(ReceiptConflictToast::class)
        ->assertSee(Money::ofMinor(-3199, Currency::Usd->value)->format())
        ->assertSee(Money::ofMinor(-3200, Currency::Eur->value)->format());
});
