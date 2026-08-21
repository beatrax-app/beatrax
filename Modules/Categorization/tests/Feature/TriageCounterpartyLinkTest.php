<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

function makeTriageLinkTx(User $user, Account $account, ImportRun $run, int $day, string $counterpartyName, ?int $counterpartyId = null): Transaction
{
    static $rowIndex = 0;
    $rowIndex++;
    $dayPart = sprintf('%02d', $day);

    return Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => "2026-05-{$dayPart}",
        'booked_at' => "2026-05-{$dayPart} 12:00:00",
        'value_date' => "2026-05-{$dayPart}",
        'amount_minor' => -1000 - $rowIndex,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000 - $rowIndex,
        'settled_currency' => 'EUR',
        'counterparty_name' => $counterpartyName,
        'counterparty_normalized' => mb_strtolower($counterpartyName),
        'normalization_version' => 1,
        'category_id' => null,
        'counterparty_id' => $counterpartyId,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad((string) $rowIndex, 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('renders the counterparty name as a link routing to counterparties.profile when resolved', function (): void {
    /** @var Counterparty $cp */
    $cp = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'albert-heijn',
        'display_name' => 'Albert Heijn',
        'merchant_name' => 'AH AMSTERDAM',
    ]);

    makeTriageLinkTx($this->user, $this->account, $this->run, day: 10, counterpartyName: 'AH Amsterdam', counterpartyId: $cp->id);

    $response = $this->get('/uncategorized');

    $response->assertOk();
    $response->assertSee('href="'.route('counterparties.profile', ['slug' => 'albert-heijn']).'"', false);
});

it('renders the counterparty name as plain text when counterparty_id is null', function (): void {
    makeTriageLinkTx($this->user, $this->account, $this->run, day: 11, counterpartyName: 'Unknown Vendor', counterpartyId: null);

    $response = $this->get('/uncategorized');

    $response->assertOk();
    $response->assertSee('Unknown Vendor');
    $response->assertSee('data-testid="triage-row-counterparty-text-', false);
    $response->assertDontSee('data-testid="triage-row-counterparty-link-', false);
});
