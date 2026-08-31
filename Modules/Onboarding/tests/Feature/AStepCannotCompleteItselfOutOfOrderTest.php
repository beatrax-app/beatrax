<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Enums\WizardStepStatus;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'out-of-order',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

function wizardStatuses(int $userId): array
{
    return DB::table('wizard_progress')
        ->where('user_id', $userId)
        ->pluck('status', 'step_key')
        ->all();
}

it('refuses to complete a step the reader could not have reached', function (): void {
    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'welcome')
        ->set('currentStepKey', 'first-import')
        ->call('next')
        ->assertSet('currentStepKey', 'first-import');

    expect(wizardStatuses($this->user->id))
        ->each->toBe(WizardStepStatus::Pending->value);
});

it('refuses to skip a step the reader could not have reached', function (): void {
    Livewire::test(SetupWizard::class)
        ->set('currentStepKey', 'first-import')
        ->call('skip')
        ->assertSet('currentStepKey', 'first-import');

    expect(wizardStatuses($this->user->id))
        ->each->toBe(WizardStepStatus::Pending->value);
});

it('refuses to complete a step key the registry does not know', function (): void {
    Livewire::test(SetupWizard::class)
        ->set('currentStepKey', 'not-a-step')
        ->call('next')
        ->assertSet('allComplete', false);

    expect(wizardStatuses($this->user->id))
        ->each->toBe(WizardStepStatus::Pending->value);
});

it('still completes the step the reader is genuinely on', function (): void {
    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'welcome')
        ->call('next')
        ->assertSet('currentStepKey', 'connect-bank');

    expect(wizardStatuses($this->user->id)['welcome'])->toBe(WizardStepStatus::Done->value);
});
