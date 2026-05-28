<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Services\ResumeStepResolver;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

uses(RefreshDatabase::class);

/*
 * Unit coverage for ResumeStepResolver: the three-branch decision that
 * decides which step the wizard lands the user on when they re-enter
 * /setup mid-flow. Matches the open-question #2 contract:
 *  - any in_progress row wins (drops the user back where they were),
 *  - else the first pending row in registry order,
 *  - else the empty-string sentinel meaning all-done/skipped (caller
 *    redirects to /).
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'resume',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

it('returns the step_key of the in_progress row when one exists', function (): void {
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-card')
        ->update(['status' => 'in_progress']);

    /** @var ResumeStepResolver $resolver */
    $resolver = $this->app->make(ResumeStepResolver::class);

    expect($resolver->resolve($this->user->id))->toBe('connect-card');
});

it('returns the first pending step in registry order when nothing is in_progress', function (): void {
    // All rows are pending after initialize() — the resolver should return
    // 'welcome' (the first registry step).
    /** @var ResumeStepResolver $resolver */
    $resolver = $this->app->make(ResumeStepResolver::class);

    expect($resolver->resolve($this->user->id))->toBe('welcome');
});

it('skips pending steps that appear before a later-pending step when earlier ones are done or skipped', function (): void {
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'welcome')
        ->update(['status' => 'done']);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->update(['status' => 'skipped']);

    /** @var ResumeStepResolver $resolver */
    $resolver = $this->app->make(ResumeStepResolver::class);

    // Registry order is welcome → connect-bank → connect-paypal → … so
    // after marking welcome=done + connect-bank=skipped, the next still-
    // pending step is connect-paypal.
    expect($resolver->resolve($this->user->id))->toBe('connect-paypal');
});

it('returns the empty-string sentinel when every step is done or skipped', function (): void {
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->update(['status' => 'done']);

    /** @var ResumeStepResolver $resolver */
    $resolver = $this->app->make(ResumeStepResolver::class);

    expect($resolver->resolve($this->user->id))->toBe('');
});
