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

/*
 * Verifies the single-file PayPal Activity CSV submit path stashes the
 * resulting ImportRun id into `wizard_progress.data['paypal_import_run_id']`
 * as a single int (mirroring the bank-step pattern, not the card-step
 * array pattern), and that the synthetic PayPal `accounts` row is
 * auto-created on first preview via the shared
 * EnsurePaypalAccountAction.
 *
 * Two behaviours under test:
 *
 *  1. A successful single-file submit dispatches `wizard.step.completed`
 *     and stashes an int paypal_import_run_id; the wizard's first-import
 *     step later reads this key to render the PayPal preview section
 *     between the bank and card sections.
 *
 *  2. The PayPal `accounts` row (iban='PAYPAL', kind='paypal',
 *     EUR-settled) is created during the first submit and the user ends
 *     up with exactly one PayPal row.
 */

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
