<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Events\WizardCompleted;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

uses(RefreshDatabase::class);

// Walking Back reopens a finished step, so a step the reader then SKIPS can be
// the advance that leaves nothing pending. Only Finish knew what to do about
// that: skip marked the row and stopped, leaving the reader on the step they
// had just dismissed, nothing raised and nowhere sent.

function skipEndsWizardUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function skipEndsWizardFinished(int $userId): void
{
    app(WizardProgressInitializer::class)->initialize($userId);

    DB::table('wizard_progress')->where('user_id', $userId)->update([
        'status' => 'done',
        'completed_at' => '2026-09-01 10:00:00',
    ]);
}

it('sends the reader home when a skip is what leaves nothing pending', function (): void {
    $user = skipEndsWizardUser('skip-ends-wizard');
    $this->actingAs($user);
    skipEndsWizardFinished((int) $user->id);

    Event::fake([WizardCompleted::class]);

    Livewire::test(SetupWizard::class)
        ->call('goToStep', 'tax-country')
        ->assertSet('currentStepKey', 'tax-country')
        ->call('skip')
        ->assertRedirect('/');

    expect(DB::table('wizard_progress')->where('user_id', $user->id)->where('step_key', 'tax-country')->value('status'))
        ->toBe('skipped');

    // Reopening a step makes the wizard unfinished again, so finishing it a
    // second way is a second completion — the guard is that something was
    // pending going in, and after a Back something was.
    Event::assertDispatched(WizardCompleted::class, 1);
});

it('leaves a skip that does not end the wizard exactly where it was', function (): void {
    $user = skipEndsWizardUser('skip-mid-wizard');
    $this->actingAs($user);
    app(WizardProgressInitializer::class)->initialize((int) $user->id);

    DB::table('wizard_progress')
        ->where('user_id', $user->id)
        ->where('step_key', 'welcome')
        ->update(['status' => 'done', 'completed_at' => '2026-09-01 10:00:00']);

    Event::fake([WizardCompleted::class]);

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStepKey', 'connect-bank')
        ->call('skip')
        ->assertNoRedirect()
        ->assertSet('currentStepKey', 'connect-paypal');

    Event::assertNotDispatched(WizardCompleted::class);
});
