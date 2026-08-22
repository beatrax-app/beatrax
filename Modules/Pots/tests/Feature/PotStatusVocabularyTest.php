<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\EnvelopeActivationService;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Enums\GoalStatus;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\CategoryKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Enums\PotStatus;
use Modules\Pots\Public\Services\PotBalanceQuery;

uses(RefreshDatabase::class);

// The pot readers and the envelope cutover both select on pots.status, the
// column PotStatus owns and PotWriter writes. Spelled as a bare string they
// fail silently: the query stays valid, nothing matches, and a pots page that
// has stopped listing looks the same as a user with no pots.

beforeEach(function (): void {
    $suffix = bin2hex(random_bytes(4));

    $this->user = User::query()->create([
        'username' => 'pot-vocabulary-'.$suffix,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Vocabulary ASN',
        'slug' => 'pot-vocab-'.$suffix,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => Currency::Eur->value,
    ]);
});

function potVocabPot(object $context, PotStatus $status, string $name, ?int $goalId = null, ?int $categoryId = null): Pot
{
    /** @var Pot $pot */
    $pot = Pot::factory()->create([
        'user_id' => $context->user->id,
        'account_id' => $context->account->id,
        'goal_id' => $goalId,
        'category_id' => $categoryId,
        'name' => $name,
        'status' => $status->value,
    ]);

    return $pot;
}

it('lists a pot stored under each status the owning enum names, on its own reader', function (): void {
    potVocabPot($this, PotStatus::Active, 'Live pot');
    potVocabPot($this, PotStatus::Archived, 'Retired pot');

    /** @var PotBalanceQuery $query */
    $query = app(PotBalanceQuery::class);

    expect(array_map(static fn ($row): string => $row->name, $query->forUser($this->user)))
        ->toBe(['Live pot'])
        ->and(array_map(static fn ($row): string => $row->name, $query->archivedForUser($this->user)))
        ->toBe(['Retired pot']);
});

// pots.status carries no CHECK trigger, so the schema names its vocabulary in
// two other places: this column default, and the partial index below.
it('reads back the column default under the active case', function (): void {
    DB::table('pots')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Default-status pot',
        'currency' => Currency::Eur->value,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    expect(DB::table('pots')->where('name', 'Default-status pot')->value('status'))
        ->toBe(PotStatus::Active->value);
});

// pots_active_goal_unique is a partial index whose WHERE clause names the
// active value in SQL. If the enum case drifts, the index stops covering the
// rows it was built for and the second insert quietly succeeds.
it('trips the partial unique index that names the active status in SQL', function (): void {
    $goal = Goal::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Vocabulary goal',
        'target_minor' => 100000,
        'target_currency' => Currency::Eur->value,
        'start_date' => '2026-01-01',
        'target_date' => '2026-12-31',
        'status' => GoalStatus::Active->value,
    ]);

    potVocabPot($this, PotStatus::Active, 'First goal pot', goalId: $goal->id);

    $context = $this;

    expect(static fn () => potVocabPot($context, PotStatus::Active, 'Second goal pot', goalId: $goal->id))
        ->toThrow(QueryException::class);
});

it('archives only the category-linked pots stored under the active status', function (): void {
    $category = Category::query()->create([
        'user_id' => null,
        'name' => 'Vocabulary groceries',
        'slug' => 'pot-vocab-groceries-'.bin2hex(random_bytes(3)),
        'kind' => CategoryKind::Expense->value,
        'display_order' => 1,
    ]);

    $active = potVocabPot($this, PotStatus::Active, 'Active groceries', categoryId: $category->id);
    $archived = potVocabPot($this, PotStatus::Archived, 'Archived groceries', categoryId: $category->id);

    app(EnvelopeActivationService::class)->activate();

    $this->assertDatabaseHas('pots', ['id' => $active->id, 'status' => PotStatus::Archived->value]);
    $this->assertDatabaseHas('pots', ['id' => $archived->id, 'status' => PotStatus::Archived->value]);
});
