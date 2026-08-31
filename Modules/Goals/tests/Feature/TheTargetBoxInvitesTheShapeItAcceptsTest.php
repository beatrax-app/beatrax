<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Models\Goal;

// The target field is already labelled with the currency the goal is kept in.
// The box under the label was not: it spelled two decimals and asked the phone
// for a decimal key even where the goal's own currency has no fraction.

function goalShapeUser(string $baseCurrency): User
{
    return User::create([
        'username' => 'goal-shape-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => $baseCurrency,
    ]);
}

it('invites a whole target from a yen reader', function (): void {
    $html = Livewire::actingAs(goalShapeUser('JPY'))->test(GoalsPage::class)->html();

    expect($html)->toContain('placeholder="0"')
        ->and($html)->not->toContain('placeholder="0.00"')
        ->and($html)->toContain('inputmode="numeric"')
        ->and($html)->not->toContain('inputmode="decimal"');
});

it('still invites two decimals from a euro reader', function (): void {
    $html = Livewire::actingAs(goalShapeUser('EUR'))->test(GoalsPage::class)->html();

    expect($html)->toContain('placeholder="0.00"')
        ->and($html)->toContain('inputmode="decimal"');
});

// target_currency is fixed at creation and can diverge from the reader's
// current base, and the label already says so — the box has to agree with it.
it('follows the edited goal own currency, not the reader base one', function (): void {
    $user = goalShapeUser('EUR');

    $goal = Goal::create([
        'user_id' => $user->id,
        'name' => 'Tokyo trip',
        'target_minor' => 500000,
        'target_currency' => 'JPY',
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => CarbonImmutable::now()->addMonthsNoOverflow(4)->toDateString(),
        'status' => 'active',
    ]);

    $html = Livewire::actingAs($user)
        ->test(GoalsPage::class)
        ->call('openEdit', $goal->id)
        ->html();

    expect($html)->toContain('placeholder="0"')
        ->and($html)->not->toContain('placeholder="0.00"')
        ->and($html)->toContain('inputmode="numeric"')
        ->and($html)->not->toContain('inputmode="decimal"');
});
