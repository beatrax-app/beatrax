<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;

// The goal form is drawn twice — a bottom sheet at phone width and a modal
// above it — and each copy walked the goals list to find out which currency to
// label the amount field with. One walk, so the two copies cannot disagree.

$gfcTemplate = static fn (): string => (string) file_get_contents(
    base_path('Modules/Goals/Resources/views/livewire/goals-page.blade.php'),
);

$gfcEditedGoalScans = static fn (string $template): int => substr_count(
    $template,
    'foreach ($rows as $goalRow)',
);

$gfcUser = static function (string $username): User {
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
};

$gfcGoal = static function (User $user, string $name, string $currency, string $status = 'active'): int {
    return (int) DB::table('goals')->insertGetId([
        'user_id' => $user->id,
        'name' => $name,
        'target_minor' => 250000,
        'target_currency' => $currency,
        'start_date' => now()->subMonthsNoOverflow(2)->toDateString(),
        'target_date' => now()->addYearNoOverflow()->toDateString(),
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
};

it('walks the goals list once to label the amount field, not once per form', function () use ($gfcTemplate, $gfcEditedGoalScans): void {
    expect($gfcEditedGoalScans($gfcTemplate()))->toBe(1);
});

it('labels both copies of the form with the base currency when adding a goal', function () use ($gfcUser, $gfcGoal): void {
    $user = $gfcUser('gfc-adding');
    $gfcGoal($user, 'Dollar goal', 'USD');

    $html = (string) Livewire::actingAs($user)->test(GoalsPage::class)->html();

    expect(substr_count($html, 'Target amount (EUR)'))->toBe(2)
        ->and($html)->not->toContain('Target amount (USD)');
});

it('labels both copies of the form with the edited goal s own currency', function () use ($gfcUser, $gfcGoal): void {
    $user = $gfcUser('gfc-editing');
    $goalId = $gfcGoal($user, 'Dollar goal', 'USD');

    $html = (string) Livewire::actingAs($user)->test(GoalsPage::class, ['editGoalId' => $goalId])->html();

    expect(substr_count($html, 'Target amount (USD)'))->toBe(2)
        ->and($html)->not->toContain('Target amount (EUR)');
});

it('falls back to the base currency when the edited id is on no listed goal', function () use ($gfcUser, $gfcGoal): void {
    $user = $gfcUser('gfc-archived');
    $archivedId = $gfcGoal($user, 'Archived dollar goal', 'USD', 'archived');

    $html = (string) Livewire::actingAs($user)->test(GoalsPage::class, ['editGoalId' => $archivedId])->html();

    expect(substr_count($html, 'Target amount (EUR)'))->toBe(2)
        ->and($html)->not->toContain('Target amount (USD)');
});
