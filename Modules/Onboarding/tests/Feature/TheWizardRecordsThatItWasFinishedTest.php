<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Events\WizardCompleted;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Http\Livewire\Steps\DoneStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

// Finish wrote nothing. The step raised the completion event itself and
// redirected, so the `done` row stayed pending for the life of the install:
// the resolver kept answering "done" as the first pending step, every later
// visit reopened the wizard there under the resume banner, and the event fired
// again on every press of a button that is supposed to end the wizard once.

beforeEach(function (): void {
    $this->reader = User::query()->create([
        'username' => 'wizard-finished',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->reader);

    $this->app->make(WizardProgressInitializer::class)->initialize($this->reader->id);

    // Everything but the terminal step behind them, which is where a reader
    // who has walked the wizard stands when they press Finish.
    DB::table('wizard_progress')
        ->where('user_id', $this->reader->id)
        ->where('step_key', '!=', 'done')
        ->update(['status' => 'done']);

    $this->completions = [];
    $this->app->make(Dispatcher::class)->listen(
        WizardCompleted::class,
        function (WizardCompleted $event): void {
            $this->completions[] = $event;
        },
    );

    $this->doneStatus = fn (): ?string => DB::table('wizard_progress')
        ->where('user_id', $this->reader->id)
        ->where('step_key', 'done')
        ->value('status');
});

it('hands the finish to the component that owns the row', function (): void {
    Livewire::test(DoneStep::class)
        ->call('finish')
        ->assertDispatched('wizard.step.completed')
        ->assertNoRedirect();
});

it('marks the terminal step done and leaves by the front door', function (): void {
    expect(($this->doneStatus)())->toBe('pending');

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'done')
        ->dispatch('wizard.step.completed')
        ->assertSet('allComplete', true)
        ->assertRedirect('/');

    expect(($this->doneStatus)())->toBe('done')
        ->and($this->completions)->toHaveCount(1);
});

it('raises the completion once however often the reader presses Finish', function (): void {
    Livewire::test(SetupWizard::class)->dispatch('wizard.step.completed');

    expect($this->completions)->toHaveCount(1);

    Livewire::test(SetupWizard::class)
        ->dispatch('wizard.step.completed')
        ->assertRedirect('/');

    expect($this->completions)->toHaveCount(1);
});

it('does not greet a reader who finished with an invitation to carry on', function (): void {
    Livewire::test(SetupWizard::class)->dispatch('wizard.step.completed');

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'done')
        ->assertSet('allComplete', true)
        ->assertSet('isResuming', false)
        ->assertDontSee('Welcome back');
});
