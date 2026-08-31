<?php

declare(strict_types=1);

// CAMT.053 and MT940 are interchange formats: the reader picking one has named
// a file type and nothing about who issued it. The step named every account it
// auto-created "ASN bank" regardless, so a reader at any other bank got their
// own money filed under a bank they had never heard the app mention.

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectBankStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'connect-bank-name',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

/** @return list<string> every bank name the CSV preset registry knows */
function everyRegisteredPresetLabel(CsvPresetRegistry $presets): array
{
    $labels = [];
    foreach ($presets->allLayouts() as $preset) {
        $labels[] = $preset->label;
    }

    return $labels;
}

function submitStatementToConnectBankStep(string $format, string $fixture, string $filename): void
{
    $contents = file_get_contents(base_path('tests/fixtures/'.$fixture));

    Livewire::test(ConnectBankStep::class)
        ->set('selectedFormat', $format)
        ->set('file', UploadedFile::fake()->createWithContent($filename, $contents !== false ? $contents : ''))
        ->call('submit')
        ->assertDispatched('wizard.step.completed');
}

it('does not name an account after a bank when the reader only picked CAMT.053', function (): void {
    submitStatementToConnectBankStep(SourceFormat::Camt053->value, 'asn-camt053-sample-1.xml', 'statement.xml');

    /** @var Account $created */
    $created = Account::query()
        ->where('user_id', $this->user->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();

    expect($created->name)->toBe('Bank account');
    foreach (everyRegisteredPresetLabel($this->app->make(CsvPresetRegistry::class)) as $label) {
        expect($created->name)->not->toContain($label);
    }
});

it('does not name an account after a bank when the reader only picked MT940', function (): void {
    submitStatementToConnectBankStep(SourceFormat::Mt940->value, 'asn-mt940-sample-1.sta', 'statement.sta');

    /** @var Account $created */
    $created = Account::query()
        ->where('user_id', $this->user->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();

    expect($created->name)->toBe('Bank account');
    foreach (everyRegisteredPresetLabel($this->app->make(CsvPresetRegistry::class)) as $label) {
        expect($created->name)->not->toContain($label);
    }
});

it('names the account after the CSV layout when that is what the reader picked', function (): void {
    $contents = file_get_contents(base_path('tests/fixtures/asn-sample-1.csv'));

    Livewire::test(ConnectBankStep::class)
        ->call('setFormat', CsvPresetRegistry::ASN)
        ->call('setCsvLayout', CsvPresetRegistry::ASN)
        ->set('file', UploadedFile::fake()->createWithContent('statement.csv', $contents !== false ? $contents : ''))
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    /** @var Account $created */
    $created = Account::query()
        ->where('user_id', $this->user->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();

    expect($created->name)->toBe('ASN account');
});
