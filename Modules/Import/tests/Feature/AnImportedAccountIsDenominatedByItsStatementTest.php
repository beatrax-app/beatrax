<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Actions\EnsureGooglePlayAccountAction;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Services\AccountNamer;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\Currency;

// Measured on an Android phone. Reporting currency JPY, a 230-row ASN
// statement whose every row says EUR, imported through the app's own upload
// flow: the account the importer minted came out default_currency JPY. On
// /reconcile the field then read "Statement balance (¥)" and MoneyInput
// refused 2158.91 outright, because a yen has no minor unit.

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->asn = base_path('tests/fixtures/asn-sample-1.csv');
    $this->revolutJpy = base_path('Modules/Ingestion/tests/fixtures/csv/revolut-jpy-sample.csv');
    $this->paypal = base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv');
    $this->icsPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');

    $this->readerWithNoAccounts = function (string $baseCurrency): User {
        $seeded = $this->seedFixtureUserAndAccount($baseCurrency);
        Account::query()->where('user_id', $seeded['user']->id)->delete();
        $this->actingAs($seeded['user']);

        return $seeded['user'];
    };

    $this->previewRunId = function (User $user, string $path, string $format, ?BankCsvFormatHint $hint = null): int {
        /** @var RunsImports $importer */
        $importer = $this->app->make(RunsImports::class);

        return $importer->runFromUpload($path, $format, $user, basename($path), $hint)->importRunId;
    };

    $this->namedAccount = fn (User $user, string $iban): Account => Account::query()
        ->where('user_id', $user->id)
        ->where('iban', $iban)
        ->firstOrFail();
});

it('stamps the euro the ASN statement states on the account a yen reader names', function (): void {
    $user = ($this->readerWithNoAccounts)(Currency::Jpy->value);

    $runId = ($this->previewRunId)($user, $this->asn, CsvPresetRegistry::ASN, BankCsvFormatHint::Asn);

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->call('nameAccount', 'NL57ASNB0123456789', 'ASN Betaalrekening')
        ->assertHasNoErrors();

    expect(($this->namedAccount)($user, 'NL57ASNB0123456789')->default_currency)->toBe(Currency::Eur->value);
});

// The mirror case, so a fix that merely hardcodes euro cannot pass: a yen
// statement read by a euro reader is a yen account.
it('stamps the yen a Revolut export states on the account a euro reader names', function (): void {
    $user = ($this->readerWithNoAccounts)(Currency::Eur->value);

    $runId = ($this->previewRunId)($user, $this->revolutJpy, CsvPresetRegistry::REVOLUT);

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->call('nameAccount', 'REVOLUT', 'Revolut Yen')
        ->assertHasNoErrors();

    expect(($this->namedAccount)($user, 'REVOLUT')->default_currency)->toBe(Currency::Jpy->value);
});

// One account legitimately holds two denominations, so there is no single
// currency the statement states. That is the one case the reader's own
// reporting currency answers, and it is a last resort rather than the default.
it('falls back to the reader when the exported rows name two currencies', function (): void {
    $user = ($this->readerWithNoAccounts)(Currency::Jpy->value);

    $mixed = tempnam(sys_get_temp_dir(), 'revolut-mixed').'.csv';
    file_put_contents($mixed, implode("\n", [
        'Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance',
        'CARD_PAYMENT,Current,2026-05-02 10:15:00,2026-05-02 12:00:00,Spotify,-9.99,0.00,EUR,COMPLETED,490.01',
        'CARD_PAYMENT,Current,2026-05-03 10:15:00,2026-05-03 12:00:00,Bodega,-12.50,0.00,USD,COMPLETED,477.51',
        '',
    ]));

    try {
        $runId = ($this->previewRunId)($user, $mixed, CsvPresetRegistry::REVOLUT);

        Livewire::test(PreviewWizard::class, ['id' => $runId])
            ->call('nameAccount', 'REVOLUT', 'Revolut Mixed')
            ->assertHasNoErrors();

        expect(($this->namedAccount)($user, 'REVOLUT')->default_currency)->toBe(Currency::Jpy->value);
    } finally {
        @unlink($mixed);
    }
});

// Nothing outside an import holds a statement, and a caller that has none must
// still get an account.
it('falls back to the reader when the namer is handed no statement currency', function (): void {
    $user = ($this->readerWithNoAccounts)(Currency::Jpy->value);

    $this->app->make(AccountNamer::class)('NL57ASNB0999000111', 'Hand Named', $user);

    expect(($this->namedAccount)($user, 'NL57ASNB0999000111')->default_currency)->toBe(Currency::Jpy->value);
});

// ICS bills its cardholders in euro and nothing else: every row's settled leg
// is EUR, whatever currency the charge was made in.
it('stamps euro on the ICS card account a yen reader names', function (): void {
    $user = ($this->readerWithNoAccounts)(Currency::Jpy->value);

    $runId = ($this->previewRunId)($user, $this->icsPdf, 'ics-pdf');

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->set('icsAccountName', 'ICS Card')
        ->call('saveIcsAccountName')
        ->assertHasNoErrors();

    expect(($this->namedAccount)($user, 'ICS-CARD')->default_currency)->toBe(Currency::Eur->value);
});

it('stamps the wallet currency the PayPal export states on the account a yen reader names', function (): void {
    $user = ($this->readerWithNoAccounts)(Currency::Jpy->value);

    $runId = ($this->previewRunId)($user, $this->paypal, 'paypal-csv');

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->set('paypalAccountName', 'PayPal')
        ->call('savePaypalAccountName')
        ->assertHasNoErrors();

    expect(($this->namedAccount)($user, EnsurePaypalAccountAction::PAYPAL_OWN_IBAN)->default_currency)
        ->toBe(Currency::Eur->value);
});

// A Play receipt prices in USD and settles in EUR only when the mail carries
// the parenthesised euro leg. This one does not, so the wallet behind it moved
// dollars -- which is neither the reader's yen nor the euro of every other
// account the importer opens.
it('stamps the dollar a Google Play receipt settled in on the account a yen reader names', function (): void {
    $user = ($this->readerWithNoAccounts)(Currency::Jpy->value);

    $eml = tempnam(sys_get_temp_dir(), 'gp-receipt').'.eml';
    file_put_contents($eml, implode("\r\n", [
        'From: googleplay-noreply@google.com',
        'To: reader@example.test',
        'Subject: Your Google Play Order Receipt',
        'Date: Sun, 17 May 2026 09:30:00 +0000',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Order number: GPA.1234-5678-9012-34567',
        'Item: Headspace subscription',
        'Total: $12.99 USD',
        '',
    ]));

    try {
        $runId = ($this->previewRunId)($user, $eml, 'eml');

        Livewire::test(PreviewWizard::class, ['id' => $runId])
            ->set('googlePlayAccountName', 'Google Play')
            ->call('saveGooglePlayAccountName')
            ->assertHasNoErrors();

        expect(($this->namedAccount)($user, EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN)->default_currency)
            ->toBe(Currency::Usd->value);
    } finally {
        @unlink($eml);
    }
});
