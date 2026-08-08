<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

/*
 * Acceptance coverage for WIZ-02 — Resume mid-wizard.
 *
 * Verifies the SetupWizard's mount-time resume detection: a user with an
 * `in_progress` step row lands directly back on that step, the
 * `isResuming` banner flag flips on, and the "Welcome back" copy
 * appears in the rendered surface.
 *
 * The wizard's URL is /setup-wizard (the literal /setup belongs to the
 * Desktop module's first-launch migration splash); SetupWizard is
 * rendered through Livewire's component testing harness because the
 * Layout attribute target is irrelevant to the state-machine
 * assertions — the parent's public properties + rendered fragment are
 * the contract under test.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'resume-wizard',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

it('lands the user back on the in-progress step with the resume banner', function (): void {
    // Walk the user past welcome + connect-bank, then leave them mid
    // connect-card — the wizard's most representative resume case.
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'welcome')
        ->update(['status' => 'done']);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->update(['status' => 'done']);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-card')
        ->update(['status' => 'in_progress']);

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'connect-card')
        ->assertSet('isResuming', true)
        ->assertSee('Welcome back');
});

it('lands the user on the first pending step without the banner when nothing is in_progress', function (): void {
    // Fresh user — every row is `pending` after initialize(). The
    // resolver returns 'welcome' (first registry step); the banner
    // stays off because the resume step IS the first step.
    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'welcome')
        ->assertSet('isResuming', false)
        ->assertSee('get Beatrax to know your money');
});
