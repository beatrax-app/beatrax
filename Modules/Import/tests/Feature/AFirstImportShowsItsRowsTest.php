<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Account;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

// On a fresh install the preview was replaced entirely by a name-this-account
// form: no rows, and Confirm rendered but disabled with nothing saying why.
// The reader was asked to name an account for a file they could not see, and
// could not tell whether they had picked the right export.
it('shows the rows it read while it asks for the account name', function (): void {
    Account::query()->where('iban', 'NL57ASNB0123456789')->delete();

    $preview = app(RunsImports::class)->runFromUpload(
        base_path('tests/fixtures/asn-sample-1.csv'),
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    $html = Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('We found an unfamiliar IBAN')
        ->html();

    expect(substr_count($html, '<tr data-row-index='))->toBe(100)
        ->and($html)->toContain('Rows shown: 100 of 229')
        // The naming state is not a failure state, and the alerts that say a
        // file could not be read must not appear over rows that read fine.
        ->and($html)->not->toContain('This file could not be read');
});

it('still shows the rows once the account is named', function (): void {
    $preview = app(RunsImports::class)->runFromUpload(
        base_path('tests/fixtures/asn-sample-1.csv'),
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    $html = Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertDontSee('We found an unfamiliar IBAN')
        ->html();

    expect(substr_count($html, '<tr data-row-index='))->toBe(100);
});

// The funding column measured 126px on an iPhone 12 mini, and a td at phone
// width takes overflow-wrap: anywhere, so every one of the hundred rows split
// its IBAN at whatever character ran out of room -- "NL10BANK00005000" over
// "01". Grouped in fours the browser breaks between groups instead.
it('draws every IBAN in groups a reader can compare, never split mid-identifier', function (): void {
    Account::query()->where('iban', 'NL57ASNB0123456789')->delete();

    $preview = app(RunsImports::class)->runFromUpload(
        base_path('tests/fixtures/asn-sample-1.csv'),
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    $html = Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])->html();

    expect($html)->toContain('<strong class="font-medium">NL57 ASNB 0123 4567 89</strong>')
        ->and($html)->toContain('<span class="font-mono text-xs">NL68 BANK 0000 0000 01</span>')
        ->and($html)->toContain('<span class="font-mono text-xs">NL41 BANK 0000 0000 02</span>');

    // Nothing the reader reads carries the unbroken form. The compact IBAN is
    // still in the page -- nameAccount() takes it as an argument -- so this
    // looks at rendered text only.
    $text = strip_tags($html);
    expect($text)->not->toContain('NL57ASNB0123456789')
        ->and($text)->not->toContain('NL68BANK0000000001');
});
