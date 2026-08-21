<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectBankStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'connect-bank-autocreate',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

it('creates a bank account for an unknown IBAN and stashes the run id', function (): void {
    expect(Account::query()->where('user_id', $this->user->id)->exists())->toBeFalse();

    $fixturePath = base_path('tests/fixtures/asn-camt053-sample-1.xml');
    $contents = file_get_contents($fixturePath);
    $upload = UploadedFile::fake()->createWithContent('statement.xml', $contents !== false ? $contents : '');

    Livewire::test(ConnectBankStep::class)
        ->set('selectedFormat', 'camt053')
        ->set('file', $upload)
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    $created = Account::query()
        ->where('user_id', $this->user->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->first();

    expect($created)->not->toBeNull();
    expect($created->kind)->toBe('bank');

    $row = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->data['bank_import_run_id'] ?? null)->toBeInt();
});

it('gives the auto-created account a name-derived slug that carries no IBAN characters', function (): void {
    $fixturePath = base_path('tests/fixtures/asn-camt053-sample-1.xml');
    $contents = file_get_contents($fixturePath);
    $upload = UploadedFile::fake()->createWithContent('statement.xml', $contents !== false ? $contents : '');

    Livewire::test(ConnectBankStep::class)
        ->set('selectedFormat', 'camt053')
        ->set('file', $upload)
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    /** @var Account $created */
    $created = Account::query()
        ->where('user_id', $this->user->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();

    expect($created->slug)->toBe(Str::slug($created->name));
    expect($created->slug)->not->toContain('456789');
    expect($created->slug)->not->toContain('0123456789');
});
