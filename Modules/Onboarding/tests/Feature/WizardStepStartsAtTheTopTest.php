<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

// Nine steps share one page, so advancing is a re-render, not a navigation,
// and the browser kept the offset: measured on device, step 2 scrolled to 433
// handed step 3 a scrollY of 424 — below its own eyebrow and heading, with the
// wizard chrome dragged under the iOS status bar.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'wizard-scroll',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

it('tells the browser to go back to the top when a step is completed', function (): void {
    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'welcome')
        ->dispatch('wizard.step.completed')
        ->assertSet('currentStepKey', 'connect-bank')
        ->assertDispatched(SetupWizard::STEP_CHANGED_EVENT);
});

it('tells the browser to go back to the top when a step is skipped', function (): void {
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'welcome')
        ->update(['status' => 'done']);

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'connect-bank')
        ->dispatch('wizard.step.skipped')
        ->assertSet('currentStepKey', 'connect-paypal')
        ->assertDispatched(SetupWizard::STEP_CHANGED_EVENT);
});

it('tells the browser to go back to the top when a completed step is revisited', function (): void {
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'welcome')
        ->update(['status' => 'done']);

    Livewire::test(SetupWizard::class)
        ->call('goToStep', 'welcome')
        ->assertSet('currentStepKey', 'welcome')
        ->assertDispatched(SetupWizard::STEP_CHANGED_EVENT);
});

it('announces under the one name the bundle listens for', function (): void {
    // The event is only worth dispatching if something moves the viewport, and
    // the listener is registered once for every screen rather than rendered
    // into this one — so the name is the only thing that can drift.
    $bundle = (string) file_get_contents(base_path('resources/js/app.js'));

    expect($bundle)->toContain("addEventListener('".SetupWizard::STEP_CHANGED_EVENT."'");
});
