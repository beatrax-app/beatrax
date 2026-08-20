<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Internal\Services\WizardStepRegistry;

uses(RefreshDatabase::class);

// Assertions read the registry rather than hard-coding a step count, so
// adding a connector does not trip this file.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'wizard-init',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

it('seeds one wizard_progress row per registry step in pending status', function (): void {
    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    /** @var WizardStepRegistry $registry */
    $registry = $this->app->make(WizardStepRegistry::class);
    $expectedSteps = $registry->steps();

    $initializer->initialize($this->user->id);

    $rows = DB::table('wizard_progress')->where('user_id', $this->user->id)->get();

    expect($rows)->toHaveCount(count($expectedSteps));

    foreach ($rows as $row) {
        expect($row->status)->toBe('pending');
    }

    $stepKeys = $rows->pluck('step_key')->sort()->values()->all();
    $expectedSorted = collect($expectedSteps)->sort()->values()->all();
    expect($stepKeys)->toBe($expectedSorted);
});

it('is idempotent — re-fire still produces one row per registry step', function (): void {
    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    /** @var WizardStepRegistry $registry */
    $registry = $this->app->make(WizardStepRegistry::class);
    $expectedCount = count($registry->steps());

    $initializer->initialize($this->user->id);
    $initializer->initialize($this->user->id);

    expect(DB::table('wizard_progress')->where('user_id', $this->user->id)->count())->toBe($expectedCount);
});

it('does not overwrite a step that has already progressed past pending', function (): void {
    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);

    $initializer->initialize($this->user->id);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'welcome')
        ->update(['status' => 'done']);

    // A duplicated UserInstalled dispatch must not demote welcome to pending.
    $initializer->initialize($this->user->id);

    $status = DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'welcome')
        ->value('status');

    expect($status)->toBe('done');
});

it('seeds a registry step added after a user finished the wizard as skipped, not pending', function (): void {
    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    // A user who finished onboarding before the step existed.
    DB::table('wizard_progress')->where('user_id', $this->user->id)->update(['status' => 'done']);
    DB::table('wizard_progress')->where('user_id', $this->user->id)->where('step_key', 'budgets')->delete();

    $initializer->initialize($this->user->id);

    $status = DB::table('wizard_progress')->where('user_id', $this->user->id)->where('step_key', 'budgets')->value('status');
    expect($status)->toBe('skipped');
});

it('seeds a newly-added registry step as pending while the user is still mid-wizard', function (): void {
    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    // The same, but mid-wizard.
    DB::table('wizard_progress')->where('user_id', $this->user->id)->where('step_key', 'budgets')->delete();

    $initializer->initialize($this->user->id);

    $status = DB::table('wizard_progress')->where('user_id', $this->user->id)->where('step_key', 'budgets')->value('status');
    expect($status)->toBe('pending');
});
