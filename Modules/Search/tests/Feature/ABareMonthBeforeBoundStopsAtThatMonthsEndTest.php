<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// `createFromFormat('Y-m', …)` leaves the day unset and PHP fills it from
// TODAY, so a 29th, 30th or 31st reading of the clock rolls a short month into
// the next one and endOfMonth() then answers for the wrong month. The bound is
// reader-typed, so what it means must not depend on the day it is typed on.

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{User, SearchQuery}
 */
function monthBoundUserAndQuery(string $username): array
{
    $user = User::findOrFail(test()->searchTestUser($username));

    return [$user, app(SearchQuery::class)];
}

it('keeps a before:February bound inside February when today is the 29th', function (): void {
    CarbonImmutable::setTestNow('2026-08-29 12:00:00');

    [$user, $query] = monthBoundUserAndQuery('before-feb-typed-on-a-29th');
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Feb', 'description' => 'coffee run', 'posted_at' => '2026-02-27', 'booked_at' => '2026-02-27 00:00:00']);
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Mar', 'description' => 'coffee run', 'posted_at' => '2026-03-10', 'booked_at' => '2026-03-10 00:00:00']);

    $page = $query->search($user, 'coffee before:2026-02', SearchFilters::empty());

    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Coffee Feb');
});

it('keeps a before:April bound inside April when today is the 31st', function (): void {
    CarbonImmutable::setTestNow('2026-01-31 12:00:00');

    [$user, $query] = monthBoundUserAndQuery('before-apr-typed-on-a-31st');
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Apr', 'description' => 'coffee run', 'posted_at' => '2026-04-30', 'booked_at' => '2026-04-30 00:00:00']);
    test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee May', 'description' => 'coffee run', 'posted_at' => '2026-05-20', 'booked_at' => '2026-05-20 00:00:00']);

    $page = $query->search($user, 'coffee before:2026-04', SearchFilters::empty());

    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Coffee Apr');
});

it('answers the same for a bare-month bound whatever day of the month it is typed on', function (): void {
    $answers = [];
    foreach (['2026-08-01', '2026-08-28', '2026-08-29', '2026-08-30', '2026-08-31'] as $today) {
        CarbonImmutable::setTestNow($today.' 12:00:00');

        [$user, $query] = monthBoundUserAndQuery('before-feb-on-'.$today);
        test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Feb', 'description' => 'coffee run', 'posted_at' => '2026-02-27', 'booked_at' => '2026-02-27 00:00:00']);
        test()->searchTestTransaction($user->id, ['counterparty_name' => 'Coffee Mar', 'description' => 'coffee run', 'posted_at' => '2026-03-10', 'booked_at' => '2026-03-10 00:00:00']);

        $answers[$today] = $query->search($user, 'coffee before:2026-02', SearchFilters::empty())->totalCount;
    }

    expect($answers)->toBe([
        '2026-08-01' => 1,
        '2026-08-28' => 1,
        '2026-08-29' => 1,
        '2026-08-30' => 1,
        '2026-08-31' => 1,
    ]);
});
