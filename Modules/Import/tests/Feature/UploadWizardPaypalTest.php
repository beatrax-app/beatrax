<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Import\Internal\Enums\ImportType;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\ImportRun;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('offers the PayPal activity download as one of the CSV layouts', function (): void {
    $component = Livewire::test(UploadWizard::class)->set('importType', ImportType::Csv->value);

    /** @var UploadWizard $instance */
    $instance = $component->instance();

    expect($instance->availableFormats())->toContain([
        'value' => SourceFormat::PaypalCsv->value,
        'label' => 'Activity Download (CSV)',
    ]);
})->group('phase-4');

it('keeps paypal-csv selectable once the reader picks it under the CSV import type', function (): void {
    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Csv->value)
        ->set('sourceFormat', SourceFormat::PaypalCsv->value)
        ->assertSet('sourceFormat', SourceFormat::PaypalCsv->value);
})->group('phase-4');

it('validates sourceFormat = paypal-csv through the in: validator', function (): void {
    $contents = file_get_contents(base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv'));
    $file = UploadedFile::fake()->createWithContent('paypal-activity.csv', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Csv->value)
        ->set('sourceFormat', SourceFormat::PaypalCsv->value)
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(ImportRun::query()->where('source_format', SourceFormat::PaypalCsv->value)->count())->toBe(1);
})->group('phase-4');

it('reaches the PayPal export through a file type on the wizard page, not through a PayPal option', function (): void {
    $response = $this->get('/imports/new');

    $response->assertOk();
    $response->assertSee('Activity Download (CSV)', false);
    $response->assertDontSee('<option value="paypal">', false);
})->group('phase-4');
