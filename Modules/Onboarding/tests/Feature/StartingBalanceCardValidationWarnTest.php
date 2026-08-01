<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Onboarding\Internal\Http\Livewire\StartingBalanceCard;

/*
 * Covers the warn-don't-block contract on the starting-balance card.
 * When a user saves a starting-balance date that is later than the
 * earliest existing `transactions.posted_at` for the account (the
 * canonical "date the row landed in the ledger" column), the card
 * surfaces an inline amber warning but still accepts the save — the
 * wizard never blocks the user, only flags the implication.
 *
 * Two behaviours:
 *
 *  1. The amber warning copy renders when the user-entered date is
 *     after the account's earliest booked transaction.
 *  2. The same save still dispatches `starting-balance.confirmed` so
 *     the parent step records the confirmation.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'sbc-warn',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var Account $account */
    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN account',
        'slug' => 'asn-warn',
        'kind' => 'bank',
        'iban' => 'NL03ASNB0123450001',
        'default_currency' => 'EUR',
    ]);

    // Need an ImportRun row to satisfy the transactions.import_run_id FK.
    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/sbc-warn-fixture.xml',
        'sha256' => hash('sha256', 'sbc-warn-fixture'),
        'uploaded_at' => CarbonImmutable::parse('2026-02-01 12:00:00'),
        'status' => 'previewed',
    ]);

    // Seed an earliest-known transaction at posted_at = 2026-01-15 so a
    // save with a later starting-balance date triggers the warning row.
    DB::table('transactions')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'import_run_id' => $run->id,
        'type' => 'expense',
        'posted_at' => '2026-01-15',
        'booked_at' => '2026-01-15 00:00:00',
        'value_date' => '2026-01-15',
        'amount_minor' => 1000,
        'currency' => 'EUR',
        'settled_amount_minor' => 1000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'fixture',
        'normalization_version' => 1,
        'description' => 'fixture row',
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'source_ref' => 'sbc-warn-fixture-1',
        'fingerprint' => hash('sha256', 'sbc-warn-fp-1'),
        'fingerprint_version' => 3,
        'created_at' => '2026-02-01 12:00:00',
        'updated_at' => '2026-02-01 12:00:00',
    ]);
});

it('shows the amber warning when the date is later than the earliest booked transaction', function (): void {
    Livewire::test(StartingBalanceCard::class, [
        'accountId' => $this->account->id,
        'accountLabel' => 'ASN account',
        'accountShort' => 'NL18 ASNB · 0001',
        'currency' => 'EUR',
        'detectedMinor' => 100000,
        'detectedDate' => '2026-01-01',
        'state' => 'detected',
    ])
        ->call('startEdit')
        ->set('editedMinor', 100000)
        ->set('editedDate', '2026-02-01')
        ->call('save')
        ->assertSee('later than your first imported transaction');
});

it('still dispatches starting-balance.confirmed when the warning is shown (warn-don\'t-block)', function (): void {
    Livewire::test(StartingBalanceCard::class, [
        'accountId' => $this->account->id,
        'accountLabel' => 'ASN account',
        'accountShort' => 'NL18 ASNB · 0001',
        'currency' => 'EUR',
        'detectedMinor' => 100000,
        'detectedDate' => '2026-01-01',
        'state' => 'detected',
    ])
        ->call('startEdit')
        ->set('editedMinor', 100000)
        ->set('editedDate', '2026-02-01')
        ->call('save')
        ->assertDispatched(
            'starting-balance.confirmed',
            accountId: $this->account->id,
            minor: 100000,
            date: '2026-02-01',
        );
});
