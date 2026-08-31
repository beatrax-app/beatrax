<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Import\Internal\Enums\ImportType;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('offers every CSV layout under the CSV import type', function (): void {
    $component = Livewire::test(UploadWizard::class)->set('importType', ImportType::Csv->value);
    /** @var UploadWizard $instance */
    $instance = $component->instance();

    expect(array_column($instance->availableFormats(), 'value'))
        ->toBe([
            CsvPresetRegistry::ASN,
            CsvPresetRegistry::ING_NL,
            CsvPresetRegistry::N26,
            CsvPresetRegistry::REVOLUT,
            SourceFormat::PaypalCsv->value,
        ]);
});

it('names a CSV layout from its preset rather than from copy written into the wizard', function (): void {
    /** @var CsvPresetRegistry $presets */
    $presets = $this->app->make(CsvPresetRegistry::class);

    $component = Livewire::test(UploadWizard::class)->set('importType', ImportType::Csv->value);
    /** @var UploadWizard $instance */
    $instance = $component->instance();

    $labels = array_column($instance->availableFormats(), 'label', 'value');

    foreach ($presets->allLayouts() as $format => $preset) {
        expect($labels[$format] ?? null)->toBe($preset->label);
    }
});

it('accepts an N26 CSV under the CSV import type', function (): void {
    $file = UploadedFile::fake()->createWithContent('n26.csv', "Booking Date,Value Date\n");

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Csv->value)
        ->set('sourceFormat', CsvPresetRegistry::N26)
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors(['sourceFormat']);
});

it('rejects a sourceFormat that does not belong to the CSV import type', function (): void {
    $file = UploadedFile::fake()->createWithContent('n26.csv', "Booking Date,Value Date\n");

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Csv->value)
        ->set('sourceFormat', SourceFormat::IcsPdf->value)
        ->set('file', $file)
        ->call('submit')
        ->assertHasErrors(['sourceFormat']);
});
