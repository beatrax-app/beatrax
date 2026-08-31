<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Public\Services\EnvelopeProgressQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

// Two categories can share a display name — two slugs whose translation
// collides, or a user category named like a default one. BudgetProgressQuery
// now hands the pair to CategoryPathName::distinct() BEFORE the fold sorts, so
// the labels differ by the time the comparator sees them and the id tiebreak
// beside it is no longer what decides. The guarantee this pins is the one a
// reader can still see: the pair comes out in id order, because distinct()
// numbers by id ascending and the bare name sorts before its numbered twin.
// Rows are picked by id rather than by label for that reason — the second one
// is no longer called "Subscriptions".

it('orders two equally-named categories by id, not by the order their envelopes were written', function (): void {
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'budget-tie-order',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $first = (int) Category::create([
        'user_id' => null,
        'name' => 'Subscriptions',
        'slug' => 'tie-order-a-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ])->id;

    $second = (int) Category::create([
        'user_id' => null,
        'name' => 'Subscriptions',
        'slug' => 'tie-order-b-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 2,
    ])->id;

    $this->actingAs($user);

    expect($second)->toBeGreaterThan($first);

    $period = app(PeriodQuery::class)->current();

    // Written highest id first, so write order is the reverse of the id order
    // the reader should get.
    foreach ([$second, $first] as $categoryId) {
        app(EnvelopeWriter::class)->setAssigned($user, $categoryId, $period->start, 1000);
    }

    /** @var EnvelopeProgressQuery $query */
    $query = app(EnvelopeProgressQuery::class);

    $ids = array_map(
        static fn (object $row): int => $row->categoryId,
        array_values(array_filter(
            $query->forPeriod($user, $period),
            static fn (object $row): bool => in_array($row->categoryId, [$first, $second], true),
        )),
    );

    expect($ids)->toBe([$first, $second]);
});
