<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

// ?force=1 is what the Settings "re-run setup tour" link uses.

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

    // All done, so the resolver returns its empty-string sentinel.
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->update(['status' => 'done']);
});

it('renders the terminal step when every wizard step is already done and no force flag is set', function (): void {
    // It used to redirect. $this->redirect() from mount() skips the render, and
    // on the phone runtime that left the layout painted around an empty slot.
    // @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-livewire-redirect-from-mount
    $response = $this->get(route('setup'));

    $response->assertOk();

    expect((string) $response->getContent())->toContain('onboarding.steps.done-step');
});

it('resets every wizard_progress row and re-enters from welcome when ?force=1 is passed', function (): void {
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
