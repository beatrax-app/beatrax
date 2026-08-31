<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Onboarding\Database\Seeders\Demo\DemoWizardProgressSeeder;
use Modules\Onboarding\Internal\Services\WizardStepRegistry;
use Modules\Onboarding\Models\WizardProgress;

uses(RefreshDatabase::class);

// The seeder listed its steps by hand and fell two behind the registry. The
// initializer backfills the rest at mount, so nothing looked broken — the
// seeded slate simply was not the wizard the reader gets.

/** @return list<string> */
function dwsSeededSteps(User $user): array
{
    $steps = WizardProgress::query()
        ->where('user_id', $user->id)
        ->pluck('step_key')
        ->all();

    return array_values(array_map(static fn (mixed $key): string => is_string($key) ? $key : '', $steps));
}

it('seeds a row for every step the registry names, and none it does not', function (): void {
    $user = User::query()->create([
        'username' => 'demo-wizard-probe',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    app(DemoWizardProgressSeeder::class)->run(['demo-1' => $user]);

    $registered = app(WizardStepRegistry::class)->steps();
    $seeded = dwsSeededSteps($user);

    expect(array_values(array_diff($registered, $seeded)))->toBe([])
        ->and(array_values(array_diff($seeded, $registered)))->toBe([]);
});

it('leaves the demo user the same slate after the whole seed command runs', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1')->firstOrFail();

    $registered = app(WizardStepRegistry::class)->steps();
    $seeded = dwsSeededSteps($user);

    expect(array_values(array_diff($registered, $seeded)))->toBe([])
        ->and(array_values(array_diff($seeded, $registered)))->toBe([]);

    $statuses = WizardProgress::query()
        ->where('user_id', $user->id)
        ->pluck('status')
        ->unique()
        ->values()
        ->all();

    foreach (['pending', 'in_progress', 'done', 'skipped'] as $status) {
        expect($statuses)->toContain($status);
    }
});
