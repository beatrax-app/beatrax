<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// Toggle 2 is a privacy control: with it off the app must not invite the user
// to contribute a merchant name anywhere. The Triage row is where that
// invitation appears, so the gate is asserted against the page that renders
// it rather than against a component nothing mounts.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'triage-cta-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'value_date' => '2026-05-05',
        'amount_minor' => -1234,
        'currency' => 'EUR',
        'settled_amount_minor' => -1234,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'CRYPTIC PAY 123',
        'counterparty_normalized' => 'cryptic pay 123',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_repeat('f', 64),
        'fingerprint_version' => 1,
    ]);
});

it('renders the CTA on a triage row when offerToContribute is unset (default on)', function (): void {
    $response = $this->get('/uncategorized');

    $response->assertOk();
    $response->assertSee('help-others-cta', false);
    $response->assertSee('Help others identify this', false);
});

it('does not render the CTA when offerToContribute is false', function (): void {
    $this->user->community_settings = ['offerToContribute' => false];
    $this->user->save();

    $response = $this->get('/uncategorized');

    $response->assertOk();
    $response->assertDontSee('help-others-cta', false);
    $response->assertDontSee('Help others identify this', false);
});

// The CTA is only ever an entry point into the shared modal; a row that
// renders it must carry the dispatch that opens it, or the button is decor.
it('wires the visible CTA to the shared suggest-mapping modal', function (): void {
    $response = $this->get('/uncategorized');

    $response->assertOk();
    $response->assertSee('suggest-mapping:open', false);
});
