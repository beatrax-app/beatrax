<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'skip-wizard',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

it('advances past a skippable step and marks the wizard_progress row as skipped', function (): void {
    // connect-bank is the first skippable step.
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'welcome')
        ->update(['status' => 'done']);

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'connect-bank')
        ->dispatch('wizard.step.skipped')
        ->assertSet('currentStepKey', 'connect-paypal');

    $status = DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->value('status');

    expect($status)->toBe('skipped');
});

it('skips only the step the reader is on, never the ones after it', function (): void {
    // The wizard has two exits and only this one writes. "Resume later" used to
    // share this method and marked all seven remaining rows skipped with it.
    // @link ../../../../.docs/features/onboarding/architecture.md#leaving-the-wizard
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'welcome')
        ->update(['status' => 'done']);
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->update(['status' => 'done']);

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'connect-paypal')
        ->dispatch('wizard.step.skipped')
        ->assertSet('currentStepKey', 'connect-card');

    $skippedRows = DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('status', 'skipped')
        ->pluck('step_key')
        ->all();

    expect($skippedRows)->toBe(['connect-paypal']);

    $pendingRows = DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('status', 'pending')
        ->pluck('step_key')
        ->all();
    sort($pendingRows);
    expect($pendingRows)->toBe(['budgets', 'connect-card', 'connect-email', 'done', 'first-import', 'tax-country']);
});

it('is a no-op on non-skippable steps', function (): void {
    // welcome is not skippable, so the dispatch must change nothing.
    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'welcome')
        ->dispatch('wizard.step.skipped')
        ->assertSet('currentStepKey', 'welcome');

    $status = DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'welcome')
        ->value('status');

    expect($status)->toBe('pending');
});
