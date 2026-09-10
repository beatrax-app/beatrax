<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Models\Account;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

// Two coffees at the same shop, for the same money, on the same day: a bank
// books both and states no time for either, so the two rows are identical in
// every column the dedup tuple reads. One of them was dropped on import and
// the reader's balance was short by a purchase they made.
function twoIdenticalCoffeesFixture(): string
{
    return base_path('tests/fixtures/asn-two-identical-coffees.csv');
}

function twoIdenticalCoffeesRowCount(): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('transactions')
        ->where('account_id', Account::query()->where('iban', 'NL57ASNB0123456789')->value('id'))
        ->where('posted_at', '2026-02-03')
        ->where('amount_minor', -350)
        ->count();
}

it('previews both bookings as new rather than calling the second one a duplicate', function (): void {
    $preview = app(RunsImports::class)->runFromUpload(
        twoIdenticalCoffeesFixture(),
        'asn-csv',
        $this->fixtureUser,
        'asn-two-identical-coffees.csv',
        BankCsvFormatHint::Asn,
    );

    $statuses = array_map(static fn ($row): string => $row->status->value, $preview->rows);

    expect($statuses)->toBe(
        [PreviewRowStatus::NewRow->value, PreviewRowStatus::NewRow->value],
        'The statement books the same purchase twice and the preview called one of them a duplicate.',
    );
});

it('writes both bookings when the reader confirms', function (): void {
    $preview = app(RunsImports::class)->runFromUpload(
        twoIdenticalCoffeesFixture(),
        'asn-csv',
        $this->fixtureUser,
        'asn-two-identical-coffees.csv',
        BankCsvFormatHint::Asn,
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])->call('confirm');

    expect(twoIdenticalCoffeesRowCount())->toBe(
        2,
        'The reader bought two coffees and the ledger kept one, so every balance below it is short by 3.50.',
    );
});

// The ordinal that separates the two is a function of the file, so the same
// file read twice hands the same ordinal to the same row. Were it a count of
// what the ledger already holds, a re-import would number the pair 2 and 3 and
// write the reader's coffees a second time.
it('adds nothing when the same statement is imported again', function (): void {
    $importer = app(RunsImports::class);

    $first = $importer->runAndConfirm(
        twoIdenticalCoffeesFixture(),
        'asn-csv',
        $this->fixtureUser,
        'asn-two-identical-coffees.csv',
        BankCsvFormatHint::Asn,
    );

    $second = $importer->runAndConfirm(
        twoIdenticalCoffeesFixture(),
        'asn-csv',
        $this->fixtureUser,
        'asn-two-identical-coffees.csv',
        BankCsvFormatHint::Asn,
    );

    expect($first->inserted)->toBe(2, 'The first import of the pair wrote '.$first->inserted.' of the two rows.')
        ->and($second->inserted)->toBe(0, 'Re-importing the same file wrote '.$second->inserted.' more rows.')
        ->and($second->duplicates)->toBe(2, 'The second read recognised '.$second->duplicates.' of the two rows it had already imported.')
        ->and(twoIdenticalCoffeesRowCount())->toBe(2);
});
