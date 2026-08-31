<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Import\Internal\Enums\ImportType;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\ImportRun;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('renders the calm upload form on GET /imports/new', function (): void {
    $response = $this->get('/imports/new');

    $response->assertOk();
    $response->assertSee('Upload statement', false);
});

it('requires a source format declaration', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../../tests/fixtures/asn-sample-1.csv');
    $file = UploadedFile::fake()->createWithContent('asn.csv', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('sourceFormat', '')
        ->set('file', $file)
        ->call('submit')
        ->assertHasErrors(['sourceFormat']);
});

it('rejects an unsupported source format with the in:asn-csv rule', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../../tests/fixtures/asn-sample-1.csv');
    $file = UploadedFile::fake()->createWithContent('asn.csv', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('sourceFormat', 'camt-053')
        ->set('file', $file)
        ->call('submit')
        ->assertHasErrors(['sourceFormat']);
});

it('rejects a file larger than 10 MB with the locked oversized copy', function (): void {
    $oversized = UploadedFile::fake()->create('big.csv', 10_241);

    Livewire::test(UploadWizard::class)
        ->set('sourceFormat', 'asn-csv')
        ->set('file', $oversized)
        ->call('submit')
        ->assertHasErrors(['file']);
});

it('rejects a non-CSV upload with the bad-MIME copy from the sniffer', function (): void {
    // .exe fails the mimes rule. A .pdf would pass it — ICS imports need that —
    // and a content-vs-declared-format mismatch is caught later at HeaderSniffer.
    $badMime = UploadedFile::fake()->create('not-a-csv.exe', 5);

    Livewire::test(UploadWizard::class)
        ->set('sourceFormat', 'asn-csv')
        ->set('file', $badMime)
        ->call('submit')
        ->assertHasErrors(['file']);
});

it('redirects to the preview page after a successful upload', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../../tests/fixtures/asn-sample-1.csv');
    $file = UploadedFile::fake()->createWithContent('asn-statement.csv', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('sourceFormat', 'asn-csv')
        ->set('file', $file)
        ->call('submit')
        ->assertRedirect();

    expect(ImportRun::count())->toBe(1);
    expect(ImportRun::query()->first()?->status)->toBe('previewed');
});

it('opens the wizard on the CSV import type with asn-csv as the default format', function (): void {
    Livewire::test(UploadWizard::class)
        ->assertSet('importType', ImportType::Csv->value)
        ->assertSet('sourceFormat', CsvPresetRegistry::ASN);
})->group('phase-3');

it('returns only the PDF format under the card-statement import type', function (): void {
    $component = Livewire::test(UploadWizard::class)->set('importType', ImportType::Pdf->value);

    /** @var UploadWizard $instance */
    $instance = $component->instance();
    $available = $instance->availableFormats();

    expect($available)->toBe([
        ['value' => SourceFormat::IcsPdf->value, 'label' => 'PDF'],
    ]);
})->group('phase-3');

it('returns only the CAMT.053 format under the CAMT.053 import type', function (): void {
    $component = Livewire::test(UploadWizard::class)->set('importType', ImportType::Camt053->value);

    /** @var UploadWizard $instance */
    $instance = $component->instance();
    $available = $instance->availableFormats();

    expect($available)->toBe([
        ['value' => SourceFormat::Camt053->value, 'label' => 'CAMT.053 (XML)'],
    ]);
})->group('phase-3');

it('resets sourceFormat to the first leaf when the import type changes', function (): void {
    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Pdf->value)
        ->assertSet('sourceFormat', SourceFormat::IcsPdf->value)
        ->set('importType', ImportType::Csv->value)
        ->assertSet('sourceFormat', CsvPresetRegistry::ASN);
})->group('phase-3');

it('lets the user pick the PDF import type and ics-pdf format and submit', function (): void {
    $pdfPath = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');
    $contents = file_get_contents($pdfPath);
    $file = UploadedFile::fake()->createWithContent('ics-statement.pdf', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Pdf->value)
        ->set('sourceFormat', SourceFormat::IcsPdf->value)
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(ImportRun::query()->where('source_format', SourceFormat::IcsPdf->value)->count())->toBe(1);
})->group('phase-3');

it('renders the two-step picker on the upload page', function (): void {
    $response = $this->get('/imports/new');

    $response->assertOk();
    $response->assertSee('Import type', false);
    $response->assertSee('Format', false);
    $response->assertSee('Drop in a CSV, CAMT.053, MT940 or PDF statement, or an email receipt file.', false);
    $response->assertSee('wire:model.live="importType"', false);
})->group('phase-3');
