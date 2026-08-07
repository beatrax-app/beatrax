<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    // makeTriagePageTx() dates its rows in May 2026, and /transactions renders
    // the `recent(daysBack: 90)` window rather than full history. With a live
    // clock those rows leave the window on 2026-08-03 and the page assertion
    // starts failing on a codebase nobody touched. Freeze the clock instead of
    // relocating the fixtures, so the dates the other cases order on stay put.
    $this->travelTo(CarbonImmutable::parse('2026-05-20 09:00:00'));

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
        'display_order' => 30,
    ]);
});

function makeTriagePageTx(User $user, Account $account, ImportRun $run, int $day, ?int $categoryId, string $counterparty = 'Merchant'): Transaction
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
        'counterparty_name' => $counterparty,
        'counterparty_normalized' => mb_strtolower($counterparty),
        'normalization_version' => 1,
        'category_id' => $categoryId,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad((string) $rowIndex, 64, 'f', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('renders the triage page with every uncategorized row, newest-first', function (): void {
    makeTriagePageTx($this->user, $this->account, $this->run, day: 5, categoryId: null, counterparty: 'Cafe Local');
    makeTriagePageTx($this->user, $this->account, $this->run, day: 12, categoryId: null, counterparty: 'AH Amsterdam');
    makeTriagePageTx($this->user, $this->account, $this->run, day: 8, categoryId: $this->groceries->id, counterparty: 'Should not show');

    $response = $this->get('/uncategorized');

    $response->assertOk();
    $response->assertSee('Uncategorized', false);
    $response->assertSee('AH Amsterdam');
    $response->assertSee('Cafe Local');
    $response->assertDontSee('Should not show');
    $response->assertSeeInOrder(['AH Amsterdam', 'Cafe Local']);
});

it('shows the "Inbox zero." empty-state when no rows are uncategorized', function (): void {
    makeTriagePageTx($this->user, $this->account, $this->run, day: 4, categoryId: $this->groceries->id, counterparty: 'AH Amsterdam');

    $response = $this->get('/uncategorized');

    $response->assertOk();
    $response->assertSee('Inbox zero.');
    $response->assertSee('Every transaction has a category.');
});

it('renders the keymap legend and the Save categories CTA', function (): void {
    makeTriagePageTx($this->user, $this->account, $this->run, day: 5, categoryId: null);

    $response = $this->get('/uncategorized');

    $response->assertOk();
    $response->assertSee('1–9 assign top categories', false);
    $response->assertSee('Enter save', false);
    $response->assertSee('Save categories');
});

it('saves staged assignments through AssignsCategory on Save categories click', function (): void {
    $tx1 = makeTriagePageTx($this->user, $this->account, $this->run, day: 1, categoryId: null);
    $tx2 = makeTriagePageTx($this->user, $this->account, $this->run, day: 2, categoryId: null);

    Livewire::actingAs($this->user)
        ->test('categorization.triage-inbox')
        ->call('selectForRow', $tx1->id, $this->groceries->id)
        ->call('selectForRow', $tx2->id, $this->groceries->id)
        ->call('save')
        ->assertSuccessful();

    expect(Transaction::find($tx1->id)->category_id)->toBe($this->groceries->id);
    expect(Transaction::find($tx2->id)->category_id)->toBe($this->groceries->id);
});

it('exposes the inline category picker on every /transactions row', function (): void {
    makeTriagePageTx($this->user, $this->account, $this->run, day: 5, categoryId: null, counterparty: 'AH Amsterdam');

    $response = $this->get('/transactions');

    $response->assertOk();
    $response->assertSee('AH Amsterdam');
    // Each row mounts the inline-category-picker Livewire component, which
    // emits a wire:model.live="categoryId" form input.
    $response->assertSee('wire:model.live="categoryId"', false);
});

it('writes through AssignsCategory when the inline picker fires updatedCategoryId', function (): void {
    $tx = makeTriagePageTx($this->user, $this->account, $this->run, day: 9, categoryId: null);

    Livewire::actingAs($this->user)
        ->test('categorization.inline-category-picker', [
            'transactionId' => $tx->id,
            'categoryId' => null,
        ])
        ->set('categoryId', $this->groceries->id)
        ->assertSuccessful();

    expect(Transaction::find($tx->id)->category_id)->toBe($this->groceries->id);
});

it("silently ignores a foreign user's category id when set on the inline picker", function (): void {
    /** @var User $foreignUser */
    $foreignUser = User::create([
        'username' => 'foreign-picker',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    /** @var Category $foreignCategory */
    $foreignCategory = Category::create([
        'user_id' => $foreignUser->id,
        'name' => 'Foreign Category',
        'slug' => 'foreign-category',
        'kind' => 'expense',
        'display_order' => 1,
    ]);
    $tx = makeTriagePageTx($this->user, $this->account, $this->run, day: 11, categoryId: null);

    // A scripted client could call `$wire.set('categoryId', <foreign id>)`.
    // The action must reject the write so the column stays NULL rather than
    // landing a cross-tenant reference.
    Livewire::actingAs($this->user)
        ->test('categorization.inline-category-picker', [
            'transactionId' => $tx->id,
            'categoryId' => null,
        ])
        ->set('categoryId', $foreignCategory->id)
        ->assertSuccessful();

    expect(Transaction::find($tx->id)->category_id)->toBeNull();
});
