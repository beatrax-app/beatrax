<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

// Signed up rather than User::create()d: `beatrax:install` migrates before it
// creates its user, so the cutover sweep that stamps the genesis anchor walked
// an empty `users` table and every reader since held the column null. A fixture
// that writes the column itself proves the fold and nothing about the install
// path that has to reach it.
function theFirstReader(): User
{
    /** @var SignupAction $signup */
    $signup = app(SignupAction::class);

    $result = $signup('first-reader', 'a-long-password-12chars');

    return $result['user'];
}

function expenseCategoryNamed(string $slug): Category
{
    /** @var Category */
    return Category::withoutGlobalScopes()->whereNull('user_id')->where('slug', $slug)->firstOrFail();
}

it('shows the move on both envelopes of a reader who signed up today', function (): void {
    $user = theFirstReader();
    $this->actingAs($user);

    $fuel = expenseCategoryNamed('transport-fuel');
    $groceries = expenseCategoryNamed('groceries');
    $period = app(PeriodQuery::class)->containingForUser($user, now()->toImmutable());

    app(EnvelopeWriter::class)->move($user, $fuel->id, $groceries->id, $period->start, 5000);

    $fold = app(CarryoverQuery::class)->forUserAndPeriod($user, $period);

    expect($fold['rows'][$fuel->id]->netMovedMinor)->toBe(-5000)
        ->and($fold['rows'][$groceries->id]->netMovedMinor)->toBe(5000)
        ->and($fold['rows'][$fuel->id]->availableMinor)->toBe(-5000)
        ->and($fold['rows'][$groceries->id]->availableMinor)->toBe(5000);
});

it('gives the reader an activation anchor at signup, not at the cutover migration', function (): void {
    $user = theFirstReader();

    $activated = app(DatabaseManager::class)->connection()
        ->table('users')
        ->where('id', $user->id)
        ->value('envelope_activated_at');

    expect($activated)->not->toBeNull();
});

// The anchor above is only established from here on. Every reader who signed up
// before it, and every device that joined one, still holds the column null, and
// their moves are the only record that they ever opened the grid: the fold's
// fallback read the earliest ASSIGNMENT and nothing else, so a reader who had
// only ever moved money had no genesis at all.
it('anchors a reader whose only envelope activity is a move', function (): void {
    $user = theFirstReader();
    $this->actingAs($user);

    $fuel = expenseCategoryNamed('transport-fuel');
    $groceries = expenseCategoryNamed('groceries');
    $period = app(PeriodQuery::class)->containingForUser($user, now()->toImmutable());

    app(EnvelopeWriter::class)->move($user, $fuel->id, $groceries->id, $period->start, 5000);

    app(DatabaseManager::class)->connection()
        ->table('users')
        ->where('id', $user->id)
        ->update(['envelope_activated_at' => null]);

    $fold = app(CarryoverQuery::class)->forUserAndPeriod($user->refresh(), $period);

    expect($fold['rows'][$fuel->id]->netMovedMinor)->toBe(-5000)
        ->and($fold['rows'][$groceries->id]->netMovedMinor)->toBe(5000);
});
