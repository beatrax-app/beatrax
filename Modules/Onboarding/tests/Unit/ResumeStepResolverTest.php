<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Services\ResumeStepResolver;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

uses(RefreshDatabase::class);

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
    // Priors done, because an in_progress step the jump guard would refuse is
    // the separate case below rather than this one.
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->whereIn('step_key', ['welcome', 'connect-bank', 'connect-paypal'])
        ->update(['status' => 'done']);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-card')
        ->update(['status' => 'in_progress']);

    /** @var ResumeStepResolver $resolver */
    $resolver = $this->app->make(ResumeStepResolver::class);

    expect($resolver->resolve($this->user->id))->toBe('connect-card');
});

it('returns the first pending step in registry order when nothing is in_progress', function (): void {
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

    // Registry order is welcome → connect-bank → connect-paypal → …
    expect($resolver->resolve($this->user->id))->toBe('connect-paypal');
});

// A step inserted into the registry ahead of the one that was in progress
// leaves that step behind a gate it cannot open, and F2's own resume clause
// answers it with the earliest step the jump guard would let the reader reach.
it('falls back to the earliest reachable pending step when the in_progress step is unreachable', function (): void {
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'welcome')
        ->update(['status' => 'done']);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-paypal')
        ->update(['status' => 'in_progress']);

    /** @var ResumeStepResolver $resolver */
    $resolver = $this->app->make(ResumeStepResolver::class);

    // connect-bank sits between the two and is still pending.
    expect($resolver->resolve($this->user->id))->toBe('connect-bank');
});

// 'done' is a real step key rather than a sentinel, so a user whose last row
// has never been completed still resumes onto it; the empty string is reserved
// for the state where nothing is left at all.
it('returns the terminal step key while its own row is still pending', function (): void {
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', '!=', 'done')
        ->update(['status' => 'done']);

    /** @var ResumeStepResolver $resolver */
    $resolver = $this->app->make(ResumeStepResolver::class);

    expect($resolver->resolve($this->user->id))->toBe('done');
});

it('returns the empty-string sentinel when every step is done or skipped', function (): void {
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->update(['status' => 'done']);

    /** @var ResumeStepResolver $resolver */
    $resolver = $this->app->make(ResumeStepResolver::class);

    expect($resolver->resolve($this->user->id))->toBe('');
});
