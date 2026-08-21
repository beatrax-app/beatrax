<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('probes the partial-failure preview', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        '/private/tmp/claude-501/-Users-wessel-Development-beatrax-app-beatrax/c9c43cdc-d66c-4d6e-9212-f44609317309/scratchpad/asn-partial.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-partial.csv',
        BankCsvFormatHint::Asn,
    );

    foreach ($preview->rows as $row) {
        dump([
            'rowIndex' => $row->rowIndex,
            'status' => $row->status,
            'bookedAt' => $row->bookedAt,
            'cp' => $row->counterpartyName,
            'amount' => $row->amountMinor,
            'error' => $row->error,
        ]);
    }

    $html = Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])->html();
    file_put_contents('/private/tmp/claude-501/-Users-wessel-Development-beatrax-app-beatrax/c9c43cdc-d66c-4d6e-9212-f44609317309/scratchpad/partial.html', $html);
    dump(['dashes' => substr_count($html, '—')]);

    expect(true)->toBeTrue();
});
