<?php

declare(strict_types=1);

// The bank step pinned its offer to a hand-written list holding two Dutch bank
// layouts, while the CSV preset registry — and the /imports screen that reads
// it — already carried Revolut and N26. A reader whose bank issues neither
// CAMT.053 nor MT940 was shown two banks they had no account at, and the
// working path for the card in their pocket was never offered.

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\ImportRun;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectBankStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'bank-step-reach',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

function ingestionCsvFixture(string $name): string
{
    $contents = file_get_contents(__DIR__.'/../../../Ingestion/tests/fixtures/csv/'.$name);

    return $contents !== false ? $contents : '';
}

function importThroughTheBankStep(string $layout, string $fixture, string $filename): ImportRun
{
    Livewire::test(ConnectBankStep::class)
        ->call('setFormat', $layout)
        ->call('setCsvLayout', $layout)
        ->set('file', UploadedFile::fake()->createWithContent($filename, ingestionCsvFixture($fixture)))
        ->call('submit')
        ->assertSet('uploadError', null)
        ->assertDispatched('wizard.step.completed');

    $progress = WizardProgress::query()
        ->where('user_id', test()->user->id)
        ->where('step_key', 'connect-bank')
        ->firstOrFail();

    return ImportRun::query()->findOrFail($progress->data['bank_import_run_id']);
}

it('offers every CSV layout the app can actually read, not a hand-picked subset', function (): void {
    /** @var CsvPresetRegistry $presets */
    $presets = $this->app->make(CsvPresetRegistry::class);

    $rendered = Livewire::test(ConnectBankStep::class)
        ->call('setFormat', CsvPresetRegistry::ASN);

    foreach ($presets->allLayouts() as $preset) {
        $rendered->assertSee($preset->label);
    }
});

it('offers a layout to a reader who banks nowhere near the Netherlands', function (): void {
    /** @var CsvPresetRegistry $presets */
    $presets = $this->app->make(CsvPresetRegistry::class);

    $dutchOnly = [CsvPresetRegistry::ASN, CsvPresetRegistry::ING_NL];

    $offered = [];
    foreach ($presets->allLayouts() as $preset) {
        $step = Livewire::test(ConnectBankStep::class)->call('setCsvLayout', $preset->format);
        if ($step->get('selectedFormat') === $preset->format) {
            $offered[] = $preset->format;
        }
    }

    expect(array_diff($offered, $dutchOnly))->not->toBe([]);
});

it('imports a Revolut export picked in the wizard through to a previewed run', function (): void {
    $run = importThroughTheBankStep(CsvPresetRegistry::REVOLUT, 'revolut-sample.csv', 'revolut.csv');

    expect($run->source_format)->toBe(CsvPresetRegistry::REVOLUT);
    expect($run->status)->toBe('previewed');
    expect($run->error_count)->toBe(0);
});

it('imports an N26 export picked in the wizard through to a previewed run', function (): void {
    $run = importThroughTheBankStep(CsvPresetRegistry::N26, 'n26-sample.csv', 'n26.csv');

    expect($run->source_format)->toBe(CsvPresetRegistry::N26);
    expect($run->status)->toBe('previewed');
    expect($run->error_count)->toBe(0);
});
