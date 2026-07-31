<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Search\Internal\Services\DidYouMeanSuggester;

// Direct coverage of DidYouMeanSuggester's guard branches: queries too
// short to suggest against, and a query whose user has no corpus to draw
// a suggestion from.

it('suggests nothing for a query shorter than four characters', function (): void {
    $user = User::findOrFail($this->searchTestUser('dym-short'));
    $this->searchTestTransaction($user->id, ['counterparty_name' => 'Albert Heijn', 'description' => 'groceries']);

    expect(app(DidYouMeanSuggester::class)->suggest($user, 'ah'))->toBeNull();
});

it('suggests nothing when the user has no counterparty corpus', function (): void {
    $user = User::findOrFail($this->searchTestUser('dym-empty'));

    expect(app(DidYouMeanSuggester::class)->suggest($user, 'heijm'))->toBeNull();
});

it('suggests the closest corpus word for a near-miss query', function (): void {
    $user = User::findOrFail($this->searchTestUser('dym-hit'));
    $this->searchTestTransaction($user->id, ['counterparty_name' => 'Albert Heijn', 'description' => 'groceries']);

    expect(app(DidYouMeanSuggester::class)->suggest($user, 'heijm'))->toBe('heijn');
});
