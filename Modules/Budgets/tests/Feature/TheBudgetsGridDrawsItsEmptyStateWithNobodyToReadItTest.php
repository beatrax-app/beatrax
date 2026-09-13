<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\Ledger\Public\Services\BaseCurrency;

// The page's own unauthenticated branch, which exists so a render with nobody
// signed in degrades to the empty grid rather than throwing. It asked
// PeriodQuery::current() for its heading and the view asked BaseCurrency for
// its "Ready to assign" figure, and both of those read the guard.
//
// The reader here counts every guard read, so "it rendered" and "it rendered
// without a reader" are told apart: BaseCurrency::code() swallows the
// NotAuthenticatedException and answers with the install default whenever
// runningInConsole() is true, which it always is under the suite.
function nobodyIsSignedInForTheBudgetsGrid(): CurrentUser
{
    return new class implements CurrentUser
    {
        public int $reads = 0;

        public function id(): int
        {
            $this->reads++;

            throw new NotAuthenticatedException('No authenticated user is bound to the current guard.');
        }

        public function user(): User
        {
            $this->reads++;

            throw new NotAuthenticatedException('No authenticated user is bound to the current guard.');
        }

        public function periodStartDay(): int
        {
            $this->reads++;

            throw new NotAuthenticatedException('No authenticated user is bound to the current guard.');
        }

        public function isAuthenticated(): bool
        {
            return false;
        }
    };
}

it('draws the empty grid with nobody signed in, and reads the guard for none of it', function (): void {
    $reader = nobodyIsSignedInForTheBudgetsGrid();

    app()->instance(CurrentUser::class, $reader);
    app()->forgetInstance(BaseCurrency::class);

    $component = Livewire::test(BudgetsPage::class)
        ->assertViewHas('rows', [])
        ->assertViewHas('toBudgetMinor', 0)
        ->assertViewHas('canGoPrevious', false)
        ->assertViewHas('canGoNext', false);

    expect($component->viewData('period')->label)->not->toBe('')
        ->and($component->viewData('currency'))->toBe(app(BaseCurrency::class)->installDefault())
        ->and($reader->reads)->toBe(0);
});
