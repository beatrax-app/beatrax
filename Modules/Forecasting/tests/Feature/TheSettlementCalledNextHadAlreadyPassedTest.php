<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Models\CardStatement;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

// A statement stays open until it is settled, and an imported historical one
// never is. The dashboard advertised its due date under "Next ICS settlement"
// months after it passed, with no year on the date to give it away.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'ics-overdue',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $this->asn = Account::query()->create([
        'user_id' => $this->user->id, 'name' => 'ASN', 'slug' => 'ico-bank',
        'kind' => 'bank', 'iban' => 'NL57ICOBANK', 'default_currency' => 'EUR',
    ]);
    $this->ics = Account::query()->create([
        'user_id' => $this->user->id, 'name' => 'ICS', 'slug' => 'ico-ics',
        'kind' => 'ics_card', 'iban' => 'ICS-ICO', 'default_currency' => 'EUR',
    ]);
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id, 'source_format' => 'ics-pdf', 'raw_file_path' => '/tmp/ico.pdf',
        'sha256' => str_repeat('c', 64), 'uploaded_at' => CarbonImmutable::parse('2026-04-01 00:00:00'),
        'status' => 'previewed',
    ]);

    Transaction::query()->create([
        'user_id' => $this->user->id, 'account_id' => $this->asn->id, 'type' => 'expense',
        'posted_at' => '2026-04-10', 'booked_at' => '2026-04-10 12:00:00', 'value_date' => '2026-04-10',
        'amount_minor' => -1000, 'currency' => 'EUR',
        'settled_amount_minor' => -1000, 'settled_currency' => 'EUR',
        'counterparty_name' => 'Seed', 'counterparty_normalized' => 'seed', 'normalization_version' => 3,
        'source_format' => 'asn-csv', 'import_run_id' => $this->run->id, 'source_row_index' => 1,
        'fingerprint' => str_pad('ico-seed', 64, 'd', STR_PAD_LEFT), 'fingerprint_version' => 3,
    ]);

    CardStatement::query()->create([
        'user_id' => $this->user->id, 'account_id' => $this->ics->id, 'import_run_id' => $this->run->id,
        'period_start' => '2026-04-01 00:00:00', 'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -52347, 'open_balance_minor' => 52347, 'state' => 'open',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('names a settlement whose due date has passed as overdue, with its year', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 09:00:00'));

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSeeText('ICS settlement overdue')
        ->assertSeeText('05 May 2026')
        ->assertDontSeeText('Next ICS settlement');
});

it('still calls a settlement still to come the next one', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 09:00:00'));

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSeeText('Next ICS settlement')
        ->assertDontSeeText('ICS settlement overdue');
});
