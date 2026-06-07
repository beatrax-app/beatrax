<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

/*
 * Acceptance coverage for WIZ-03 — Skip-step + Skip-rest behaviour.
 *
 * Three cases:
 *
 *  1. A skippable step (connect-bank) advances when the parent
 *     SetupWizard's `skip` event handler fires. The wizard_progress
 *     row for the skipped step lands in status `skipped`; the parent's
 *     `currentStepKey` advances to the next registry step.
 *
 *  2. The `skipRest` action marks every non-`done` row `skipped` and
 *     redirects to `/`. A user who has already done `welcome` and
 *     `connect-bank` keeps those rows intact; the remaining five
 *     (connect-paypal, connect-card, connect-email, first-import, done)
 *     all flip to `skipped` in one call.
 *
 *  3. The `skip` handler is a no-op on non-skippable steps (welcome,
 *     first-import, done) — the WizardStepRegistry's `isSkippable`
 *     guard prevents advancement.
 */

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
    // Walk past welcome so the parent's currentStepKey resumes on
    // connect-bank (the first skippable connector step).
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
    // User has finished welcome + connect-bank; the remaining five
    // rows (connect-paypal, connect-card, connect-email, first-import,
    // done) should all flip to skipped when the user hits "Resume
    // later →".
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
    expect($skippedRows)->toBe(['budgets', 'connect-card', 'connect-email', 'connect-paypal', 'done', 'first-import']);
});

it('is a no-op on non-skippable steps', function (): void {
    // Default state is welcome (a non-skippable step). Dispatching
    // wizard.step.skipped from welcome must leave currentStepKey on
    // welcome and the row's status unchanged.
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
