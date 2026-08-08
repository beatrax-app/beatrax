<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

/*
 * Acceptance coverage for WIZ-06 — Re-run the wizard.
 *
 * Two cases:
 *
 *  1. A user whose wizard_progress rows are all `done` hits the wizard
 *     URL with no force flag — the SetupWizard's mount() returns a
 *     redirect to / (the empty-string sentinel from ResumeStepResolver
 *     bails out of the wizard cleanly).
 *
 *  2. The same user hits the wizard URL with `?force=1` — mount() runs
 *     a per-user UPDATE on wizard_progress that resets every row to
 *     `pending` + `completed_at=null`, then resumes from `welcome`
 *     (banner is off because force=1 was explicit). The Settings → re-
 *     run-setup-tour link uses this affordance.
 *
 * The wizard's URL is `/setup-wizard` (the literal /setup belongs to
 * the Desktop module's first-launch migration splash). Cases that need
 * to exercise the HTTP layer use `route('setup')` symbolically; the
 * cases that only need to verify component-level mount behaviour go
 * through Livewire's component-testing harness so the assertions stay
 * focused on the SetupWizard's public state.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'rerun-wizard',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    // Walk every row to `done` so the resume resolver returns the
    // empty-string sentinel.
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->update(['status' => 'done']);
});

it('redirects to / when every wizard step is already done and no force flag is set', function (): void {
    $response = $this->get(route('setup'));

    $response->assertRedirect('/');
});

it('resets every wizard_progress row and re-enters from welcome when ?force=1 is passed', function (): void {
    // Sanity-check the precondition — every row is `done` after the
    // beforeEach manipulation.
    $nonPendingBefore = DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('status', '!=', 'pending')
        ->count();
    expect($nonPendingBefore)->toBe(9);

    Livewire::withQueryParams(['force' => '1'])
        ->test(SetupWizard::class)
        ->assertSet('currentStepKey', 'welcome')
        ->assertSet('isResuming', false)
        ->assertSee('get Beatrax to know your money');

    $nonPendingAfter = DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('status', '!=', 'pending')
        ->count();
    expect($nonPendingAfter)->toBe(0);
});
