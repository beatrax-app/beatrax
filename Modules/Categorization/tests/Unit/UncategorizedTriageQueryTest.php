<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Public\Dto\TriageBatch;
use Modules\Categorization\Public\Dto\TriageRow;
use Modules\Categorization\Public\Services\UncategorizedTriageQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Fmt;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

function makeTriageTx(User $user, Account $account, ImportRun $run, int $day, ?int $categoryId): Transaction
{
    static $rowIndex = 0;
    $rowIndex++;
    $dayPart = sprintf('%02d', $day);

    return Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => "2026-05-{$dayPart}",
        'booked_at' => "2026-05-{$dayPart} 12:00:00",
        'value_date' => "2026-05-{$dayPart}",
        'amount_minor' => -1000 - $rowIndex,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000 - $rowIndex,
        'settled_currency' => 'EUR',
        'counterparty_name' => "Merchant {$rowIndex}",
        'counterparty_normalized' => "merchant {$rowIndex}",
        'normalization_version' => 1,
        'category_id' => $categoryId,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad((string) $rowIndex, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
    ]);
});

it('returns only uncategorized transactions ordered newest-first', function (): void {
    makeTriageTx($this->user, $this->account, $this->run, day: 1, categoryId: null);
    makeTriageTx($this->user, $this->account, $this->run, day: 5, categoryId: $this->groceries->id);
    makeTriageTx($this->user, $this->account, $this->run, day: 10, categoryId: null);
    makeTriageTx($this->user, $this->account, $this->run, day: 15, categoryId: null);

    /** @var UncategorizedTriageQuery $q */
    $q = $this->app->make(UncategorizedTriageQuery::class);
    $batch = $q->for($this->user, limit: 50);

    expect($batch)->toBeInstanceOf(TriageBatch::class);
    expect($batch->rows)->toHaveCount(3);
    expect($batch->hasMore)->toBeFalse();
    expect($batch->nextCursorId)->toBeNull();

    // Newest-first (descending posted_at).
    expect($batch->rows[0])->toBeInstanceOf(TriageRow::class);

    // The row carries the reader's own short date, so the expectation has to
    // name a locale — English writes it with slashes.
    expect($batch->rows[0]->postedAt)->toContain('15/05/2026');
});

it('writes the date the way the reader\'s language does', function (): void {
    makeTriageTx($this->user, $this->account, $this->run, day: 15, categoryId: null);

    /** @var UncategorizedTriageQuery $q */
    $q = $this->app->make(UncategorizedTriageQuery::class);

    // Pre-formatted into the row at query time, so the locale has to be the
    // reader's before the query runs. It used to be a fixed d-m-Y in every
    // language, which is the one separator no locale but Dutch writes.
    app()->setLocale('nl');
    expect($q->for($this->user, limit: 50)->rows[0]->postedAt)->toContain('15-05-2026');

    app()->setLocale('de');
    expect($q->for($this->user, limit: 50)->rows[0]->postedAt)->toContain('15.05.2026');

    app()->setLocale('en');
    expect($q->for($this->user, limit: 50)->rows[0]->postedAt)->toContain('15/05/2026');
});

it('paginates with cursors when there are more rows than the page limit', function (): void {
    for ($i = 1; $i <= 15; $i++) {
        makeTriageTx($this->user, $this->account, $this->run, day: $i, categoryId: null);
    }

    /** @var UncategorizedTriageQuery $q */
    $q = $this->app->make(UncategorizedTriageQuery::class);

    $page1 = $q->for($this->user, limit: 5);
    expect($page1->rows)->toHaveCount(5);
    expect($page1->hasMore)->toBeTrue();
    expect($page1->nextCursorId)->not->toBeNull();

    $page2 = $q->for($this->user, limit: 5, cursorId: $page1->nextCursorId);
    expect($page2->rows)->toHaveCount(5);
    expect($page2->hasMore)->toBeTrue();

    $page3 = $q->for($this->user, limit: 5, cursorId: $page2->nextCursorId);
    expect($page3->rows)->toHaveCount(5);
    expect($page3->hasMore)->toBeFalse();
    expect($page3->nextCursorId)->toBeNull();
});

it('returns an empty batch when nothing is uncategorized', function (): void {
    makeTriageTx($this->user, $this->account, $this->run, day: 5, categoryId: $this->groceries->id);

    /** @var UncategorizedTriageQuery $q */
    $q = $this->app->make(UncategorizedTriageQuery::class);
    $batch = $q->for($this->user);

    expect($batch->rows)->toBeEmpty();
    expect($batch->hasMore)->toBeFalse();
    expect($batch->nextCursorId)->toBeNull();
});

it('scopes results to the requested user only', function (): void {
    makeTriageTx($this->user, $this->account, $this->run, day: 1, categoryId: null);
    makeTriageTx($this->user, $this->account, $this->run, day: 2, categoryId: null);

    $other = User::create([
        'username' => 'other',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $otherAccount = Account::create([
        'user_id' => $other->id,
        'name' => 'Other',
        'slug' => 'other',
        'kind' => 'bank',
        'iban' => 'NL08ASNB9999999999',
        'default_currency' => 'EUR',
    ]);
    $otherRun = ImportRun::create([
        'user_id' => $other->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/y.csv',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    makeTriageTx($other, $otherAccount, $otherRun, day: 20, categoryId: null);

    /** @var UncategorizedTriageQuery $q */
    $q = $this->app->make(UncategorizedTriageQuery::class);
    $batch = $q->for($this->user);

    expect($batch->rows)->toHaveCount(2);
});

// The inbox pages on TransactionCursor, which sorts (posted_at, id) descending.
// The row used to print booked_at, which an ICS card writes a day later than
// posted_at, so the dates in the inbox read out of order on a card statement.
it('prints the day it sorted the row by, not the day the card booked it', function (): void {
    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-06 12:00:00',
        'value_date' => '2026-05-05',
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'KLM ROYAL DUTCH AIR',
        'counterparty_normalized' => 'klm royal dutch air',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'ics-pdf',
        'import_run_id' => $this->run->id,
        'source_row_index' => 900,
        'fingerprint' => str_pad('900', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    /** @var UncategorizedTriageQuery $q */
    $q = $this->app->make(UncategorizedTriageQuery::class);

    expect($q->for($this->user, limit: 50)->rows[0]->postedAt)->toBe(Fmt::shortDate('2026-05-05'));
});
