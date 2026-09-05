<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

uses(RefreshDatabase::class);

// The Back button is the only way a reader moves to a step that is not the one
// the wizard put them on, and "Resume later" writes nothing. Between the two,
// a step someone walked back to and was working on was recorded as finished,
// so returning skipped past it to the step they had already left.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'back-then-resume',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->app->make(WizardProgressInitializer::class)->initialize($this->user->id);

    $this->rowFor = fn (string $stepKey): object => DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', $stepKey)
        ->first(['status', 'completed_at']);

    // welcome and connect-bank completed in order, which is the only way the
    // wizard hands anyone a Back button pointing at connect-bank.
    $this->reachConnectPaypal = fn () => Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'welcome')
        ->dispatch('wizard.step.completed')
        ->assertSet('currentStepKey', 'connect-bank')
        ->dispatch('wizard.step.completed')
        ->assertSet('currentStepKey', 'connect-paypal');
});

it('resumes at the step the reader went back to rather than the one they left', function (): void {
    ($this->reachConnectPaypal)()
        ->call('goToStep', 'connect-bank')
        ->assertSet('currentStepKey', 'connect-bank')
        ->call('leaveForNow')
        ->assertRedirect('/');

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'connect-bank')
        ->assertSet('isResuming', true);
});

it('records the step it navigated back to as in progress and un-completes it', function (): void {
    ($this->reachConnectPaypal)();

    expect(($this->rowFor)('connect-bank')->status)->toBe('done')
        ->and(($this->rowFor)('connect-bank')->completed_at)->not->toBeNull();

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'connect-paypal')
        ->call('goToStep', 'connect-bank');

    expect(($this->rowFor)('connect-bank')->status)->toBe('in_progress')
        ->and(($this->rowFor)('connect-bank')->completed_at)->toBeNull();
});

// The reopened step is still the reader's to finish: completing it again puts
// them back on the step they had reached, so nothing the UI offers is blocked
// by the step it just un-completed.
it('carries the reader forward again once they finish the reopened step', function (): void {
    ($this->reachConnectPaypal)()
        ->call('goToStep', 'connect-bank')
        ->dispatch('wizard.step.completed')
        ->assertSet('currentStepKey', 'connect-paypal');

    expect(($this->rowFor)('connect-bank')->status)->toBe('done');
});

// Reopening a step puts a not-done row in front of every step after it, so the
// jump guard refuses all of them until it is finished again. What must not
// happen is that finishing it walks the reader back through the ones they had
// already completed.
it('returns the reader to the furthest step they had reached, not the one after the reopened step', function (): void {
    Livewire::test(SetupWizard::class)
        ->dispatch('wizard.step.completed')
        ->dispatch('wizard.step.completed')
        ->dispatch('wizard.step.completed')
        ->dispatch('wizard.step.completed')
        ->assertSet('currentStepKey', 'connect-email')
        ->call('goToStep', 'connect-bank')
        ->assertSet('currentStepKey', 'connect-bank')
        ->dispatch('wizard.step.completed')
        ->assertSet('currentStepKey', 'connect-email');

    expect(($this->rowFor)('connect-paypal')->status)->toBe('done')
        ->and(($this->rowFor)('connect-card')->status)->toBe('done');
});

it('writes nothing for a step the jump guard refuses', function (): void {
    ($this->reachConnectPaypal)()
        ->call('goToStep', 'budgets')
        ->assertSet('currentStepKey', 'connect-paypal');

    expect(($this->rowFor)('budgets')->status)->toBe('pending');
});
