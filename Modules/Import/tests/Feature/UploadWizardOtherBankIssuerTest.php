<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('offers the three CSV presets under the other-bank issuer', function (): void {
    $component = Livewire::test(UploadWizard::class)->set('issuer', CsvPresetRegistry::ISSUER);
    /** @var UploadWizard $instance */
    $instance = $component->instance();

    expect(array_column($instance->availableFormats(), 'value'))
        ->toBe([CsvPresetRegistry::N26, CsvPresetRegistry::REVOLUT, CsvPresetRegistry::ING_NL]);
});

it('renders the other-bank option under the value the wizard maps formats by', function (): void {
    expect(Livewire::test(UploadWizard::class)->html())
        ->toContain('<option value="'.CsvPresetRegistry::ISSUER.'">');
});

it('accepts an N26 CSV under the other-bank issuer', function (): void {
    $file = UploadedFile::fake()->createWithContent('n26.csv', "Booking Date,Value Date\n");

    Livewire::test(UploadWizard::class)
        ->set('issuer', CsvPresetRegistry::ISSUER)
        ->set('sourceFormat', CsvPresetRegistry::N26)
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors(['sourceFormat']);
});

it('rejects a sourceFormat that does not belong to the other-bank issuer', function (): void {
    $file = UploadedFile::fake()->createWithContent('n26.csv', "Booking Date,Value Date\n");

    Livewire::test(UploadWizard::class)
        ->set('issuer', CsvPresetRegistry::ISSUER)
        ->set('sourceFormat', 'ics-pdf')
        ->set('file', $file)
        ->call('submit')
        ->assertHasErrors(['sourceFormat']);
});
