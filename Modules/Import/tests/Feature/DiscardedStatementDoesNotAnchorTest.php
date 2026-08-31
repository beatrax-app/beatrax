<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Import\Public\Actions\DiscardImport;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Account;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
});

it('does not anchor a starting balance from a statement the reader discarded', function (): void {
    $preview = $this->importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-camt053-sample-1.xml',
        'camt053',
        $this->fixtureUser,
        'asn-camt053-sample-1.xml',
    );

    $this->app->make(DiscardImport::class)($preview->importRunId, $this->fixtureUser);

    $this->importer->runAndConfirm(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();

    expect($account->starting_balance_minor)->toBeNull();
});

it('does not anchor a starting balance from a preview the reader never confirmed', function (): void {
    $this->importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-camt053-sample-1.xml',
        'camt053',
        $this->fixtureUser,
        'asn-camt053-sample-1.xml',
    );

    $this->importer->runAndConfirm(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();

    expect($account->starting_balance_minor)->toBeNull();
});

it('still anchors a starting balance from a statement the reader confirmed', function (): void {
    $this->importer->runAndConfirm(
        __DIR__.'/../../../../tests/fixtures/asn-camt053-sample-1.xml',
        'camt053',
        $this->fixtureUser,
        'asn-camt053-sample-1.xml',
    );

    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();

    expect($account->starting_balance_minor)->toBe(215891);
});
