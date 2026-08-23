<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'leave-wizard',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->app->make(WizardProgressInitializer::class)->initialize($this->user->id);

    $this->markDone = function (string ...$steps): void {
        DB::table('wizard_progress')
            ->where('user_id', $this->user->id)
            ->whereIn('step_key', $steps)
            ->update(['status' => 'done']);
    };

    $this->statusesBySteps = fn (): array => DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->pluck('status', 'step_key')
        ->all();
});

it('leaves every unfinished step exactly as it was', function (): void {
    // The affordance says "Resume later" and its aria-label says it saves your
    // progress. On a device it marked all seven remaining rows skipped, which
    // abandoned the 229 parsed transactions staged on first-import.
    ($this->markDone)('welcome', 'connect-bank');

    $before = ($this->statusesBySteps)();

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'connect-paypal')
        ->call('leaveForNow')
        ->assertRedirect('/');

    expect(($this->statusesBySteps)())->toBe($before);
});

it('comes back to the step it left, under the resume banner', function (): void {
    ($this->markDone)('welcome', 'connect-bank', 'connect-paypal', 'connect-card', 'connect-email');

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'first-import')
        ->call('leaveForNow');

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'first-import')
        ->assertSet('isResuming', true)
        ->assertSee('Welcome back');
});

it('renders the last step rather than a blank page once nothing is left to resume', function (): void {
    // Every row done or skipped is the state advance() reaches after the last
    // step. mount() used to answer it with $this->redirect('/'), which skips
    // the render; the phone kept the 200 and drew the layout around nothing.
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->update(['status' => 'skipped']);

    $response = $this->get('/setup-wizard');

    $response->assertOk();

    $body = (string) $response->getContent();

    // The shell and a step inside it. Asserted through the component name
    // rather than a class, because a class is a styling decision and this is
    // about whether anything was rendered at all.
    expect($body)->toContain('wiz-page')
        ->and($body)->toContain('onboarding.steps.done-step');
});

it('sets allComplete when it lands on that last step', function (): void {
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->update(['status' => 'done']);

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'done')
        ->assertSet('allComplete', true)
        ->assertSet('isResuming', false);
});
