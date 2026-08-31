<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Models\Account;
use Modules\Pots\Public\Exceptions\PotLinkedToCategoryException;
use Modules\Pots\Public\Services\PotWriter;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'link-goal-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-link-goal',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000021',
        'default_currency' => 'EUR',
    ]);

    $this->goal = Goal::create([
        'user_id' => $this->user->id,
        'name' => 'Emergency fund',
        'start_date' => '2026-01-01',
        'target_minor' => 500000,
        'target_currency' => 'EUR',
        'target_date' => '2026-12-31',
        'status' => 'active',
    ]);

    $this->writer = app(PotWriter::class);
});

// A pot write carrying a name the user never touched wins the name field under
// the per-field LWW merge, so a rename made on the other device disappears the
// moment this one links a goal.
it('captures only the goal column, so a link cannot overwrite a name', function (): void {
    $pot = $this->writer->save($this->user, 'Buffer', null, $this->account->id, null, null);

    Event::fake([EntityMutated::class]);

    // Re-resolved after the fake: the writer holds the dispatcher it was built
    // with, so the instance from beforeEach still has the real one.
    app(PotWriter::class)->linkGoal($this->user, $pot->id, $this->goal->id);

    Event::assertDispatched(
        EntityMutated::class,
        static fn (EntityMutated $event): bool => $event->table === 'pots'
            && array_keys($event->dirtyFields) === ['goal_id'],
    );
});

it('leaves the pot name alone when it links a goal', function (): void {
    $pot = $this->writer->save($this->user, 'Buffer', null, $this->account->id, null, null);

    DB::table('pots')->where('id', $pot->id)->update(['name' => 'Noodfonds']);

    $this->writer->linkGoal($this->user, $pot->id, $this->goal->id);

    expect(DB::table('pots')->where('id', $pot->id)->value('name'))->toBe('Noodfonds')
        ->and(DB::table('pots')->where('id', $pot->id)->value('goal_id'))->toBe($this->goal->id);
});

// The pot invariant, enforced by Pots. The Goals page used to hold it because
// the whole-row update it went through would have nulled category_id.
it('refuses to hang a goal on a category-linked pot', function (): void {
    $categoryId = DB::table('categories')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'Groceries',
        'slug' => 'groceries-link-goal',
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $potId = DB::table('pots')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => null,
        'category_id' => $categoryId,
        'name' => 'Legacy category pot',
        'currency' => 'EUR',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->writer->linkGoal($this->user, $potId, $this->goal->id);
})->throws(PotLinkedToCategoryException::class);

it('unlinks without asking for a name', function (): void {
    $pot = $this->writer->save($this->user, 'Buffer', null, $this->account->id, $this->goal->id, null);

    $this->writer->linkGoal($this->user, $pot->id, null);

    expect(DB::table('pots')->where('id', $pot->id)->value('goal_id'))->toBeNull();
});
