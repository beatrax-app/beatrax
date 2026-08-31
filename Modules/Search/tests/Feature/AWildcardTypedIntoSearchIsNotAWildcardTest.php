<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Search\Internal\Services\EntityNameSearch;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Enums\SearchEntityKind;
use Modules\Search\Public\Services\SearchQuery;

// Three LIKE predicates escaped % and _ and then ran without an ESCAPE clause.
// SQLite gives LIKE no escape character unless the predicate names one, so the
// backslash stayed a literal: the wildcard kept wildcarding AND the needle grew
// a character no keyboard sent, which matches nothing at all.

function likeDb(): Connection
{
    return app(DatabaseManager::class)->connection();
}

function likeUser(string $name): User
{
    return User::query()->create([
        'username' => $name.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function likeGoal(User $user, string $name): void
{
    likeDb()->table('goals')->insert([
        'user_id' => $user->id,
        'name' => $name,
        'target_minor' => 500000,
        'target_currency' => 'EUR',
        'start_date' => '2026-01-01',
        'target_date' => '2026-12-31',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return list<string>
 */
function likeGoalLabels(User $user, string $query): array
{
    return collect(app(EntityNameSearch::class)->query($user, $query))
        ->where('type', SearchEntityKind::Goal->value)
        ->pluck('label')
        ->values()
        ->all();
}

it('reads a typed per-cent sign as the character it is, not as "anything"', function (): void {
    $user = likeUser('like-percent');
    likeGoal($user, '50% off ticket');
    likeGoal($user, '50 percent off ticket');

    expect(likeGoalLabels($user, '50%'))->toBe(['50% off ticket']);
});

it('reads a typed underscore as the character it is, not as "any one character"', function (): void {
    $user = likeUser('like-underscore');
    likeGoal($user, 'Q_1 buffer');
    likeGoal($user, 'Qx1 buffer');

    expect(likeGoalLabels($user, 'Q_1'))->toBe(['Q_1 buffer']);
});

it('still finds a name that really does carry a backslash', function (): void {
    $user = likeUser('like-backslash');
    likeGoal($user, 'back\\slash fund');
    likeGoal($user, 'backslash fund');

    expect(likeGoalLabels($user, 'back\\slash'))->toBe(['back\\slash fund']);
});

it('escapes the same way on the recurring series OR-branch', function (): void {
    $user = likeUser('like-recurring');

    likeDb()->table('recurring_series')->insert([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'CASHBACK 5% NL',
        'display_name_override' => null,
        'latest_amount_minor' => -1599,
        'latest_currency' => 'EUR',
        'cluster_key' => 'cashback-'.$user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    likeDb()->table('recurring_series')->insert([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'CASHBACK 5 EUR NL',
        'display_name_override' => null,
        'latest_amount_minor' => -1599,
        'latest_currency' => 'EUR',
        'cluster_key' => 'cashback-eur-'.$user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $labels = collect(app(EntityNameSearch::class)->query($user, '5% NL'))
        ->where('type', SearchEntityKind::Recurring->value)
        ->pluck('label')
        ->values()
        ->all();

    expect($labels)->toBe(['CASHBACK 5% NL']);
});

// The sub-3-character words of a query cannot be MATCH predicates, so they
// narrow through a LIKE over the indexed body instead — the same predicate,
// and until now the same missing ESCAPE clause.
it('escapes the short-word LIKE that narrows an FTS match', function (): void {
    $userId = $this->searchTestUser('like-short-word');
    /** @var User $user */
    $user = User::query()->findOrFail($userId);

    $wanted = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Coffee Corner',
        'description' => 'coffee %5 discount',
    ]);
    $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Coffee Corner',
        'description' => 'coffee 45 discount',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $page = $searchQuery->search($user, 'coffee %5', SearchFilters::empty());

    expect(array_map(static fn (object $row): int => $row->id, $page->rows))->toBe([$wanted]);
});

// The category token already paired its pattern with an ESCAPE clause. It now
// shares the one helper, so it has to keep behaving the way it always did.
it('keeps the category token matching an underscore literally', function (): void {
    $userId = $this->searchTestUser('like-category-token');
    /** @var User $user */
    $user = User::query()->findOrFail($userId);

    $wantedCategory = likeDb()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Q_1 costs',
        'slug' => 'q-1-costs-'.$userId,
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherCategory = likeDb()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Qx1 costs',
        'slug' => 'qx1-costs-'.$userId,
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $wanted = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Coffee Corner',
        'description' => 'coffee run',
        'category_id' => $wantedCategory,
    ]);
    $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Coffee Corner',
        'description' => 'coffee run',
        'category_id' => $otherCategory,
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $page = $searchQuery->search($user, 'coffee category:Q_1', SearchFilters::empty());

    expect(array_map(static fn (object $row): int => $row->id, $page->rows))->toBe([$wanted]);
});
