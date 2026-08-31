<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectBankStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'connect-bank-fmt',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

it('renders three format chips above the drop zone', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->assertSee('CAMT.053')
        ->assertSee('MT940')
        ->assertSee('CSV');
});

it('drop zone is interactive when CAMT.053 is selected', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->set('selectedFormat', 'camt053')
        ->assertDontSeeHtml('aria-disabled="true"');
});

it('drop zone is interactive when MT940 is selected', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->set('selectedFormat', 'mt940')
        ->assertDontSeeHtml('aria-disabled="true"');
});

it('drop zone is disabled when CSV is selected without a layout chip', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->call('setFormat', 'asn-csv')
        ->assertSeeHtml('aria-disabled="true"');
});

it('drop zone becomes interactive when CSV is paired with a layout chip', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->call('setFormat', 'asn-csv')
        ->call('setCsvLayout', 'asn-csv')
        ->assertDontSeeHtml('aria-disabled="true"');
});

it('makes a layout chip select the preset format the import runs as', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->call('setFormat', 'asn-csv')
        ->call('setCsvLayout', CsvPresetRegistry::ING_NL)
        ->assertSet('selectedFormat', CsvPresetRegistry::ING_NL)
        ->assertSet('csvLayoutPicked', true)
        ->assertDontSeeHtml('aria-disabled="true"');
});

it('re-gates the drop zone when the format chip moves back off the picked layout', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->call('setFormat', 'asn-csv')
        ->call('setCsvLayout', CsvPresetRegistry::ING_NL)
        ->call('setFormat', 'asn-csv')
        ->assertSet('csvLayoutPicked', false)
        ->assertSeeHtml('aria-disabled="true"');
});

it('refuses a submit made from the CSV landing default before a layout is named', function (): void {
    $upload = UploadedFile::fake()->createWithContent('statement.csv', "Datum\n20260501\n");

    Livewire::test(ConnectBankStep::class)
        ->call('setFormat', 'asn-csv')
        ->set('file', $upload)
        ->call('submit')
        ->assertHasErrors('csvLayoutPicked')
        ->assertNotDispatched('wizard.step.completed');
});

it('stashes bank_import_run_id into wizard_progress.data after a successful submit', function (): void {
    // Without a matching account the CAMT fixture's own IBAN resolves to
    // UnknownAccount, every row errors, and no ImportRun id is stashed.
    Account::query()->updateOrCreate(
        ['iban' => 'NL57ASNB0123456789'],
        [
            'user_id' => $this->user->id,
            'name' => 'ASN',
            'slug' => 'asn-fixture',
            'kind' => 'bank',
            'default_currency' => 'EUR',
        ],
    );

    $fixturePath = base_path('tests/fixtures/asn-camt053-sample-1.xml');
    $contents = file_get_contents($fixturePath);
    $upload = UploadedFile::fake()->createWithContent('statement.xml', $contents !== false ? $contents : '');

    Livewire::test(ConnectBankStep::class)
        ->set('selectedFormat', 'camt053')
        ->set('file', $upload)
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    $row = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->data)->toBeArray();
    expect($row->data['bank_import_run_id'] ?? null)->toBeInt();
    expect($row->data['bank_import_run_id'])->toBeGreaterThan(0);
});

it('imports an ING CSV picked in onboarding through to a previewed run', function (): void {
    Account::query()->updateOrCreate(
        ['iban' => 'NL91ABNA0417164300'],
        [
            'user_id' => $this->user->id,
            'name' => 'ING',
            'slug' => 'ing-fixture',
            'kind' => 'bank',
            'default_currency' => 'EUR',
        ],
    );

    $fixturePath = __DIR__.'/../../../Ingestion/tests/fixtures/csv/ing-nl-sample.csv';
    $contents = file_get_contents($fixturePath);
    $upload = UploadedFile::fake()->createWithContent('ing.csv', $contents !== false ? $contents : '');

    Livewire::test(ConnectBankStep::class)
        ->call('setFormat', 'asn-csv')
        ->call('setCsvLayout', CsvPresetRegistry::ING_NL)
        ->set('file', $upload)
        ->call('submit')
        ->assertSet('uploadError', null)
        ->assertDispatched('wizard.step.completed');

    $row = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->first();

    /** @var ImportRun $run */
    $run = ImportRun::query()->findOrFail($row->data['bank_import_run_id']);
    expect($run->source_format)->toBe(CsvPresetRegistry::ING_NL);
    expect($run->status)->toBe('previewed');
    expect($run->error_count)->toBe(0);
});
