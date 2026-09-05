<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Internal\Services\WizardStepRegistry;

// The footer sits outside the step switch, so its privacy pill rendered on all
// nine steps — including connect-email, whose two primary buttons hand the
// reader's mailbox to Google or Microsoft. "Your data stays on this device"
// under "Authorize with Gmail" is the shape this file exists to stop.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'wizard-footer-promise',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    $this->reach = function (string $stepKey): void {
        /** @var WizardStepRegistry $registry */
        $registry = $this->app->make(WizardStepRegistry::class);

        foreach ($registry->steps() as $step) {
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

it('tells the reader on the connector step that the step reaches a provider', function (): void {
    ($this->reach)('connect-email');

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'connect-email')
        ->assertSeeText('This step connects your mailbox to Google or Microsoft.');
});

it('keeps the blanket promise on a step that connects nothing', function (): void {
    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'welcome')
        ->assertSeeText('Your data stays on this device')
        ->assertDontSeeText('This step connects your mailbox to Google or Microsoft.');
});

it('reads which step reaches out from the registry, not from the view', function (): void {
    /** @var WizardStepRegistry $registry */
    $registry = $this->app->make(WizardStepRegistry::class);

    expect($registry->reachesAThirdParty('connect-email'))->toBeTrue();

    foreach ($registry->steps() as $step) {
        if ($step === 'connect-email') {
            continue;
        }

        expect($registry->reachesAThirdParty($step))->toBeFalse($step.' now claims to reach a third party.');
    }
});
