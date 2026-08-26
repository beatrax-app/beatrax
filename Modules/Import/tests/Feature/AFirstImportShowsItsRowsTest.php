<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Account;

beforeEach(function (): void {
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
