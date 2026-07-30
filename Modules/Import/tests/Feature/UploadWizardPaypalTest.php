<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Ledger\Models\ImportRun;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('returns only the Activity Download CSV format when the issuer is PayPal', function (): void {
    $component = Livewire::test(UploadWizard::class)->set('issuer', 'paypal');

    /** @var UploadWizard $instance */
    $instance = $component->instance();
    $available = $instance->availableFormats();

    expect($available)->toBe([
        ['value' => 'paypal-csv', 'label' => 'Activity Download (CSV)'],
    ]);
})->group('phase-4');

it('resets sourceFormat to paypal-csv when the issuer switches to PayPal', function (): void {
    Livewire::test(UploadWizard::class)
        ->set('issuer', 'paypal')
        ->assertSet('sourceFormat', 'paypal-csv');
})->group('phase-4');

it('validates sourceFormat = paypal-csv through the in: validator', function (): void {
    $contents = file_get_contents(base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv'));
    $file = UploadedFile::fake()->createWithContent('paypal-activity.csv', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('issuer', 'paypal')
        ->set('sourceFormat', 'paypal-csv')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(ImportRun::query()->where('source_format', 'paypal-csv')->count())->toBe(1);
})->group('phase-4');

it('renders the PayPal issuer option on the wizard page', function (): void {
    $response = $this->get('/imports/new');

    $response->assertOk();
    $response->assertSee('PayPal', false);
})->group('phase-4');
