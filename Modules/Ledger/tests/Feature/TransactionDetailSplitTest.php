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
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Models\TransactionSplit;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;

/*
 * Feature tests for the inline split editor on TransactionDetail (Phase
 * 13.1 Plan 05). Covers the full lifecycle per the plan's <behavior>
 * block: fresh-open seeding, the server-truthful remaining gate, per-leg
 * validation, add/remove-leg, unsplit/remove-to-one collapse, per-leg tax
 * independence, and the transfer-type gate.
 */

uses(RefreshDatabase::class);

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
        'kind' => 'asn',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/x.xml',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'groceries-tdst', 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'household-tdst', 'kind' => 'expense', 'display_order' => 2]);
});

/** Persist an €80 expense (unsplit) for the fixture user, categorised as Groceries. */
function tdstExpense(int $userId, int $accountId, int $runId, ?int $categoryId): Transaction
{
    static $i = 500000;
    $i++;
    $today = CarbonImmutable::now()->toDateString();

    return Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'category_id' => $categoryId,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i,
        'fingerprint' => str_pad((string) $i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('opensEditorSeedingTwoLegsWithNoDbRows', function (): void {
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->assertSet('editingSplit', true)
        ->assertSet('legs.0.categoryId', $this->groceries->id)
        ->assertSet('legs.0.amount', '80,00')
        ->assertSet('legs.1.categoryId', null)
        ->assertSet('legs.1.amount', '0,00')
        ->assertSet('remainingMinor', 0);

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(0);
})->group('phase-13.1');

it('blocksSaveServerSideWhenRemainingIsNonZero', function (): void {
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->set('legs.0.amount', '60,00')
        ->set('legs.0.categoryId', $this->groceries->id)
        ->set('legs.1.amount', '10,00') // leaves €10 unassigned of the €80 total
        ->set('legs.1.categoryId', $this->household->id)
        ->assertSet('remainingMinor', 1000)
        ->call('saveSplit')
        ->assertSet('splitError', "Couldn't save — leg totals must match the transaction total exactly.");

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(0);
})->group('phase-13.1');

it('savesWhenRemainingIsExactlyZero', function (): void {
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->set('legs.0.amount', '60,00')
        ->set('legs.0.categoryId', $this->groceries->id)
        ->set('legs.1.amount', '20,00')
        ->set('legs.1.categoryId', $this->household->id)
        ->assertSet('remainingMinor', 0)
        ->call('saveSplit')
        ->assertSet('splitError', null)
        ->assertSet('hasPersistedSplit', true)
        ->assertDispatched('toast');

    $legs = TransactionSplit::query()->where('transaction_id', $tx->id)->orderBy('sort_order')->get();
    expect($legs)->toHaveCount(2);
    expect((int) $legs[0]->settled_amount_minor)->toBe(-6000);
    expect((int) $legs[1]->settled_amount_minor)->toBe(-2000);
    expect((int) $legs->sum('settled_amount_minor'))->toBe(-8000);
})->group('phase-13.1');

it('rejectsAZeroAmountLeg', function (): void {
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->set('legs.0.amount', '80,00')
        ->set('legs.0.categoryId', $this->groceries->id)
        ->set('legs.1.amount', '0,00')
        ->set('legs.1.categoryId', $this->household->id)
        ->call('saveSplit')
        ->assertSet('splitError', "Amount can't be €0,00");

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(0);
})->group('phase-13.1');

it('rejectsAMissingCategory', function (): void {
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->set('legs.0.amount', '60,00')
        ->set('legs.0.categoryId', $this->groceries->id)
        ->set('legs.1.amount', '20,00')
        ->set('legs.1.categoryId', '') // left blank
        ->call('saveSplit')
        ->assertSet('splitError', 'Choose a category.');

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(0);
})->group('phase-13.1');

it('coercesEveryLegToTheParentsSignSoAnOppositeSignEntryCannotBeSaved', function (): void {
    // The absolute-amount-only input (Req 3/D-03) can never itself produce
    // an opposite-sign leg — a literal "-60,00" fails the parse regex
    // entirely (no leading minus is accepted), so it contributes €0 to the
    // live remaining recompute. That leaves remainingMinor non-zero, which
    // the server-side gate rejects BEFORE the per-leg parse loop would
    // otherwise report the leg itself as invalid — either way, save is
    // blocked and nothing persists. Every leg that DOES parse is coerced to
    // the parent's sign inside saveSplit(), so a genuine opposite-sign row
    // can never reach SaveTransactionSplit from this editor.
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->set('legs.0.amount', '-60,00')
        ->set('legs.0.categoryId', $this->groceries->id)
        ->set('legs.1.amount', '20,00')
        ->set('legs.1.categoryId', $this->household->id)
        ->call('saveSplit')
        ->assertSet('splitError', fn (?string $error): bool => $error !== null);

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(0);

    // A validly-parsed pair always persists coerced to the parent's (negative) sign.
    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->set('legs.0.amount', '60,00')
        ->set('legs.0.categoryId', $this->groceries->id)
        ->set('legs.1.amount', '20,00')
        ->set('legs.1.categoryId', $this->household->id)
        ->call('saveSplit');

    $legs = TransactionSplit::query()->where('transaction_id', $tx->id)->get();
    foreach ($legs as $leg) {
        expect((int) $leg->settled_amount_minor)->toBeLessThan(0);
    }
})->group('phase-13.1');

it('addsAndRemovesLegsInMemory', function (): void {
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->call('addLeg')
        ->assertCount('legs', 3)
        ->call('removeLeg', 2)
        ->assertCount('legs', 2);

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(0);
})->group('phase-13.1');

it('removingToOneLegRoutesToConfirmAndCollapsesOnConfirm', function (): void {
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);
    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->assertSet('hasPersistedSplit', true)
        ->call('removeLeg', 1)
        ->assertSet('confirmRemoveToOne', true)
        ->assertSet('pendingRemoveIndex', 1)
        ->call('confirmRemoveToOneAction')
        ->assertSet('hasPersistedSplit', false)
        ->assertDispatched('toast');

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(0);
    $tx->refresh();
    expect($tx->category_id)->toBe($this->groceries->id); // the OTHER leg (index 0) survives
})->group('phase-13.1');

it('unsplitDefaultsToTheLargerMagnitudeLegAndCollapsesOnConfirm', function (): void {
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);
    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('unsplit')
        ->assertSet('confirmUnsplit', true)
        ->assertSet('unsplitSurvivorIndex', 0) // €60 Groceries leg (index 0) is the larger magnitude
        ->call('confirmUnsplitAction')
        ->assertSet('hasPersistedSplit', false)
        ->assertDispatched('toast');

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(0);
    $tx->refresh();
    expect($tx->category_id)->toBe($this->groceries->id);
})->group('phase-13.1');

it('unsplitSurvivorCanBeFlippedBeforeConfirming', function (): void {
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);
    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('unsplit')
        ->call('selectUnsplitSurvivor', 1)
        ->assertSet('unsplitSurvivorIndex', 1)
        ->call('confirmUnsplitAction');

    $tx->refresh();
    expect($tx->category_id)->toBe($this->household->id);
})->group('phase-13.1');

it('perLegTaxStatesAreIndependent', function (): void {
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);
    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $legIds = TransactionSplit::query()->where('transaction_id', $tx->id)->orderBy('sort_order')->pluck('id');

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->assertSet('legs.0.tax', false)
        ->assertSet('legs.1.tax', false)
        ->call('toggleLegTax', 0)
        ->assertSet('legs.0.tax', true)
        ->assertSet('legs.1.tax', false);

    $tagRows = DB::connection()
        ->table('tax_transaction_tags')
        ->where('transaction_id', $tx->id)
        ->get();

    expect($tagRows)->toHaveCount(1);
    expect((int) $tagRows[0]->transaction_split_id)->toBe((int) $legIds[0]);

    // Toggling back off removes the leg-scoped tag row.
    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->assertSet('legs.0.tax', true)
        ->call('toggleLegTax', 0)
        ->assertSet('legs.0.tax', false);

    expect(DB::connection()->table('tax_transaction_tags')->where('transaction_id', $tx->id)->count())->toBe(0);
})->group('phase-13.1');

it('toggleLegTaxIsANoOpForAnUnsavedLeg', function (): void {
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->assertSet('legs.0.id', null)
        ->call('toggleLegTax', 0)
        ->assertSet('legs.0.tax', false);

    expect(DB::connection()->table('tax_transaction_tags')->where('transaction_id', $tx->id)->count())->toBe(0);
})->group('phase-13.1');

it('transferTypesShowNoSplitSection', function (): void {
    $today = CarbonImmutable::now()->toDateString();
    $tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'transfer_out',
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => -10000,
        'currency' => 'EUR',
        'settled_amount_minor' => -10000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Settlement',
        'counterparty_normalized' => 'settlement',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 999001,
        'fingerprint' => str_pad('999001', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $response = $this->get(route('transactions.show', $tx->id));

    $response->assertOk();
    $response->assertDontSee('data-testid="split-section"', false);
    $response->assertDontSee('Split into categories', false);
})->group('phase-13.1');

it('rendersTheNotYetSplitEmptyStateOnAnExpenseDetailPage', function (): void {
    $tx = tdstExpense($this->user->id, $this->account->id, $this->run->id, $this->groceries->id);

    $response = $this->get(route('transactions.show', $tx->id));

    $response->assertOk();
    $response->assertSee('data-testid="split-section"', false);
    $response->assertSee('Split into categories', false);
    $response->assertSee('Groceries', false);
})->group('phase-13.1');
