<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

// Nine steps share one URL, so a step change is a re-render and the browser
// keeps no history of it. With no control of its own the wizard could only be
// left: on the phone the system back gesture walked out of it entirely and
// landed on /recovery-codes, which is deliberately a 404 once the ceremony is
// over. The reader told at the review step that a source "needs re-upload" had
// nothing to press.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'wizard-back',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    $this->reach = function (string $stepKey): void {
        $steps = ['welcome', 'connect-bank', 'connect-paypal', 'connect-card', 'connect-email', 'first-import', 'budgets', 'tax-country', 'done'];

        foreach ($steps as $step) {
            if ($step === $stepKey) {
                return;
            }

            DB::table('wizard_progress')
                ->where('user_id', $this->user->id)
                ->where('step_key', $step)
                ->update(['status' => 'done']);
        }
    };
});

it('offers a way back once a step has been left behind', function (): void {
    ($this->reach)('connect-paypal');

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'connect-paypal')
        ->assertSeeHtml('wire:click="goToStep(\'connect-bank\')"');
});

it('offers no way back from the first step', function (): void {
    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'welcome')
        ->assertDontSeeHtml('wire:click="goToStep(');
});

it('names the previous step for the platform back gesture', function (): void {
    ($this->reach)('first-import');

    Livewire::test(SetupWizard::class)
        ->assertSeeHtml('data-wizard-previous-step="connect-email"');
});

it('carries the reader back to the step they came from', function (): void {
    ($this->reach)('first-import');

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'first-import')
        ->call('goToStep', 'connect-bank')
        ->assertSet('currentStepKey', 'connect-bank');
});
