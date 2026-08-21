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

it('marks every non-done step skipped and redirects to / when skipRest is called', function (): void {
    // With welcome and connect-bank done, "Resume later" must flip the
    // remaining seven rows to skipped in one call.
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
        ->call('skipRest')
        ->assertRedirect('/');

    $doneRows = DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('status', 'done')
        ->pluck('step_key')
        ->all();
    sort($doneRows);
    expect($doneRows)->toBe(['connect-bank', 'welcome']);

    $skippedRows = DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('status', 'skipped')
        ->pluck('step_key')
        ->all();
    sort($skippedRows);
    expect($skippedRows)->toBe(['budgets', 'connect-card', 'connect-email', 'connect-paypal', 'done', 'first-import', 'tax-country']);
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
