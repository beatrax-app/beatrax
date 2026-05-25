<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

uses(RefreshDatabase::class);

/*
 * Unit coverage for WizardProgressInitializer: the seeder that lands the
 * six wizard_progress rows for a freshly-installed user, and the
 * idempotency guard that lets re-fires from a UserInstalled listener
 * never duplicate or overwrite already-progressed steps.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'wizard-init',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

it('seeds exactly six wizard_progress rows in pending status', function (): void {
    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);

    $initializer->initialize($this->user->id);

    $rows = DB::table('wizard_progress')->where('user_id', $this->user->id)->get();

    expect($rows)->toHaveCount(6);

    foreach ($rows as $row) {
        expect($row->status)->toBe('pending');
    }

    $stepKeys = $rows->pluck('step_key')->sort()->values()->all();
    expect($stepKeys)->toBe([
        'connect-bank',
        'connect-card',
        'connect-email',
        'done',
        'first-import',
        'welcome',
    ]);
});

it('is idempotent — re-fire still produces exactly six rows', function (): void {
    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);

    $initializer->initialize($this->user->id);
    $initializer->initialize($this->user->id);

    expect(DB::table('wizard_progress')->where('user_id', $this->user->id)->count())->toBe(6);
});

it('does not overwrite a step that has already progressed past pending', function (): void {
    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);

    $initializer->initialize($this->user->id);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'welcome')
        ->update(['status' => 'done']);

    // A re-fire (e.g. from a duplicated UserInstalled dispatch) must not
    // demote the welcome row back to pending.
    $initializer->initialize($this->user->id);

    $status = DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'welcome')
        ->value('status');

    expect($status)->toBe('done');
});
