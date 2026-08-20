<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectPaypalStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

// PayPal stashes a single int, following the bank step rather than the
// card step's array.
beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'connect-paypal-stash',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    $this->paypalFixturePath = base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv');
});

it('stashes paypal_import_run_id in wizard_progress.data after a successful submit', function (): void {
    $contents = file_get_contents($this->paypalFixturePath);
    expect($contents)->toBeString();

    $csv = UploadedFile::fake()->createWithContent('activity.csv', $contents);

    Livewire::test(ConnectPaypalStep::class)
        ->set('activityCsv', $csv)
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    $row = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-paypal')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->data)->toBeArray();
    expect($row->data['paypal_import_run_id'] ?? null)->toBeInt();
    expect($row->data['paypal_import_run_id'])->toBeGreaterThan(0);
});

it('auto-creates the PayPal account via EnsurePaypalAccountAction on first preview', function (): void {
    $countBefore = Account::query()
        ->where('user_id', $this->user->id)
        ->where('iban', 'PAYPAL')
        ->count();
    expect($countBefore)->toBe(0);

    $contents = file_get_contents($this->paypalFixturePath);
    expect($contents)->toBeString();

    $csv = UploadedFile::fake()->createWithContent('activity.csv', $contents);

    Livewire::test(ConnectPaypalStep::class)
        ->set('activityCsv', $csv)
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    $countAfter = Account::query()
        ->where('user_id', $this->user->id)
        ->where('iban', 'PAYPAL')
        ->count();
    expect($countAfter)->toBe(1);

    $row = Account::query()
        ->where('user_id', $this->user->id)
        ->where('iban', 'PAYPAL')
        ->first();
    expect($row)->not->toBeNull();
    expect($row->kind)->toBe('paypal');
    expect($row->default_currency)->toBe('EUR');
});
