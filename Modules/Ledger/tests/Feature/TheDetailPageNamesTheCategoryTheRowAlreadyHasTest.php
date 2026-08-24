<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;

uses(RefreshDatabase::class);

// On the Samsung, /transactions/268 read "Category —" while the "Rule that
// fired" line four lines below it on the same screen read
// `Counterparty contains "Takeaway.com" → Eating out`, and the row's
// category_id was 23. The split editor was the one place in the app reading
// the category through the Eloquent relation, and Category carries
// BelongsToUser — whose UserScope adds `categories.user_id = <reader>`.
//
// The whole shipped tree is seeded with user_id = NULL, so that relation
// resolves to null for every default category on every install. 135 of the
// 270 rows imported here were categorised and not one of them could say so.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'detail-category',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-detail-category',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456781',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/detail-category.xml',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

function detailCategoryExpense(int $userId, int $accountId, int $runId, int $categoryId): int
{
    $today = CarbonImmutable::now()->toDateString();

    return (int) DB::table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => $today,
        'booked_at' => $today.' 00:00:00',
        'value_date' => $today,
        'amount_minor' => -1903,
        'currency' => 'EUR',
        'settled_amount_minor' => -1903,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'takeaway com payments b v',
        'normalization_version' => 1,
        'description' => 'Takeaway order',
        'category_id' => $categoryId,
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'fingerprint' => str_repeat('c', 64),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('names a shared default category, which no install owns', function (): void {
    // user_id null is what DefaultCategoryTreeSeeder writes for the whole
    // shipped tree, so this is the ordinary case rather than an edge one.
    $category = Category::create([
        'user_id' => null,
        'name' => 'Eating out',
        'slug' => 'eating-out-dcat',
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $id = detailCategoryExpense($this->user->id, $this->account->id, $this->run->id, $category->id);

    Livewire::test(TransactionDetail::class, ['transactionId' => $id])
        ->assertSeeHtml('data-testid="split-current-category"')
        ->assertSee('Eating out');
});

it('names a category the reader renamed for themselves too', function (): void {
    $category = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Lunch runs',
        'slug' => 'lunch-runs-dcat',
        'kind' => 'expense',
        'display_order' => 2,
    ]);

    $id = detailCategoryExpense($this->user->id, $this->account->id, $this->run->id, $category->id);

    Livewire::test(TransactionDetail::class, ['transactionId' => $id])
        ->assertSee('Lunch runs');
});
