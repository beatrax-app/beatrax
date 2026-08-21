<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

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
    // Left mid connect-card: the representative resume case.
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
    // The banner stays off because the resume step is the first step.
    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'welcome')
        ->assertSet('isResuming', false)
        ->assertSee('get Beatrax to know your money');
});
