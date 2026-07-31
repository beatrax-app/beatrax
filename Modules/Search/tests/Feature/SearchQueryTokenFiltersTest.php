<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// Exercises SearchQuery's token-driven amount/date filters, the amount
// direction filter, and cursor pagination — the parsing and query-shaping
// branches that plain text search never reaches.

/**
 * @return array{User, SearchQuery}
 */
function tokenUserAndQuery(string $username): array
{
    $user = User::findOrFail(test()->searchTestUser($username));

    return [$user, app(SearchQuery::class)];
}

it('applies a less-than amount token as a max filter', function (): void {
    [$user, $query] = tokenUserAndQuery('token-lt');
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Small', 'description' => 'coffee run', 'settled_amount_minor' => -5000]);
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Big', 'description' => 'coffee run', 'settled_amount_minor' => -15000]);

    $page = $query->search($user, 'coffee amount:<100', SearchFilters::empty());

    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Coffee Small');
});

it('applies a hyphen amount token as an inclusive range filter', function (): void {
    [$user, $query] = tokenUserAndQuery('token-range');
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Small', 'description' => 'coffee run', 'settled_amount_minor' => -5000]);
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Big', 'description' => 'coffee run', 'settled_amount_minor' => -15000]);

    $page = $query->search($user, 'coffee amount:40-60', SearchFilters::empty());

    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Coffee Small');
});

it('applies a bare amount token as an exact filter', function (): void {
    [$user, $query] = tokenUserAndQuery('token-exact');
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Small', 'description' => 'coffee run', 'settled_amount_minor' => -5000]);
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Big', 'description' => 'coffee run', 'settled_amount_minor' => -15000]);

    $page = $query->search($user, 'coffee amount:50', SearchFilters::empty());

    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Coffee Small');
});

it('applies a month-granular before token by widening to the month end', function (): void {
    [$user, $query] = tokenUserAndQuery('token-before-month');
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Jan', 'description' => 'coffee run', 'posted_at' => '2026-01-20', 'booked_at' => '2026-01-20 00:00:00']);
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Feb', 'description' => 'coffee run', 'posted_at' => '2026-02-05', 'booked_at' => '2026-02-05 00:00:00']);

    $page = $query->search($user, 'coffee before:2026-01', SearchFilters::empty());

    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Coffee Jan');
});

it('applies full-date after and before tokens together', function (): void {
    [$user, $query] = tokenUserAndQuery('token-date-range');
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Early', 'description' => 'coffee run', 'posted_at' => '2026-01-05', 'booked_at' => '2026-01-05 00:00:00']);
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Mid', 'description' => 'coffee run', 'posted_at' => '2026-01-15', 'booked_at' => '2026-01-15 00:00:00']);
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Late', 'description' => 'coffee run', 'posted_at' => '2026-01-25', 'booked_at' => '2026-01-25 00:00:00']);

    $page = $query->search($user, 'coffee after:2026-01-10 before:2026-01-20', SearchFilters::empty());

    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Coffee Mid');
});

it('filters by amount direction in', function (): void {
    [$user, $query] = tokenUserAndQuery('token-dir-in');
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Refund', 'description' => 'coffee run', 'amount_minor' => 2500]);
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Spend', 'description' => 'coffee run', 'amount_minor' => -2500]);

    $page = $query->search($user, 'coffee', new SearchFilters(amountDirection: 'in'));

    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Coffee Refund');
});

it('filters by amount direction out', function (): void {
    [$user, $query] = tokenUserAndQuery('token-dir-out');
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Refund', 'description' => 'coffee run', 'amount_minor' => 2500]);
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Spend', 'description' => 'coffee run', 'amount_minor' => -2500]);

    $page = $query->search($user, 'coffee', new SearchFilters(amountDirection: 'out'));

    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Coffee Spend');
});

it('paginates with a posted-at cursor across pages', function (): void {
    [$user, $query] = tokenUserAndQuery('token-cursor');
    foreach (range(1, 3) as $i) {
        test()->searchTestTransaction($user->id, [
            'counterparty_name' => "Coffee {$i}",
            'description' => 'coffee run',
            'posted_at' => sprintf('2026-01-%02d', $i),
            'booked_at' => sprintf('2026-01-%02d 00:00:00', $i),
        ]);
    }

    $first = $query->search($user, 'coffee', SearchFilters::empty(), null, null, 2);
    expect($first->rows)->toHaveCount(2)
        ->and($first->hasMore)->toBeTrue()
        ->and($first->nextCursorPostedAt)->not->toBeNull();

    $second = $query->search($user, 'coffee', SearchFilters::empty(), $first->nextCursorId, $first->nextCursorPostedAt, 2);
    expect($second->rows)->toHaveCount(1)
        ->and($second->hasMore)->toBeFalse();
});
