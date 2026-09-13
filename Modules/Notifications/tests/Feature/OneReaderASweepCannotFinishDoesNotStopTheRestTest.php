<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Budgets\Public\Services\BudgetNudgeDispatch;
use Modules\Core\Models\User;
use Modules\Notifications\Tests\Support\BusThatRefusesOneReadersJob;

uses(RefreshDatabase::class);

// Three scheduled passes walk every user in this module, and until PerUserPass
// each one ended the walk on the first throw: the readers after it got nothing,
// every hour or every day, with the failure recorded only as "the command
// failed". Which reader it was, and that the rest were never reached, was not
// anywhere.

function everyReaderSweptUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{0: User, 1: User, 2: User}
 */
function everyReaderSweptThree(string $prefix): array
{
    return [
        everyReaderSweptUser($prefix.'-first'),
        everyReaderSweptUser($prefix.'-second'),
        everyReaderSweptUser($prefix.'-third'),
    ];
}

it('emits the budget nudges of the readers after the one whose dispatch throws', function (): void {
    [$first, $second, $third] = everyReaderSweptThree('nudges');

    $bus = new BusThatRefusesOneReadersJob((int) $second->id);
    $this->app->bind(BudgetNudgeDispatch::class, static fn (): BudgetNudgeDispatch => new BudgetNudgeDispatch($bus));

    expect(Artisan::call('budgets:emit-nudges'))->toBe(0);

    expect($bus->accepted)->toBe([(int) $first->id, (int) $third->id]);
    expect(Artisan::output())
        ->toContain('Budget nudges: emitted for 2 users.')
        ->toContain('Could not finish for 1 user');
});

it('prunes the inboxes of the readers after the one whose dispatch throws', function (): void {
    [$first, $second, $third] = everyReaderSweptThree('prune');

    $bus = new BusThatRefusesOneReadersJob((int) $second->id);
    $this->app->instance(Dispatcher::class, $bus);
    $this->app->instance(Illuminate\Contracts\Bus\Dispatcher::class, $bus);

    expect(Artisan::call('notifications:prune'))->toBe(0);

    expect($bus->accepted)->toBe([(int) $first->id, (int) $third->id]);
});

it('finishes the daily pass and says how many readers it could not finish for', function (): void {
    everyReaderSweptThree('daily');

    Log::spy();
    CarbonImmutable::setTestNow('2026-08-29 09:15:00');

    // The two lines before the per-trigger attempt() calls are the ones nothing
    // guarded: this device's own preference read is one of them, and a schema
    // it cannot reach is how a real one fails.
    Schema::drop('device_registry');

    expect(Artisan::call('notifications:daily-triggers'))->toBe(0);

    expect(Artisan::output())
        ->toContain('Daily triggers: emitted for 0 users.')
        ->toContain('Could not finish for 3 users');

    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message): bool => str_contains(
            $message,
            'notifications:daily-triggers: the pass could not finish for one reader',
        ))
        ->times(3);

    CarbonImmutable::setTestNow();
});
