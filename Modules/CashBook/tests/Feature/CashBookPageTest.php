<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'cashbook-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

it('renders the cash book', function (): void {
    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->assertOk()
        ->assertSee('Cash book');
});

it('records a manual expense into the canonical ledger', function (): void {
    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('direction', 'expense')
        ->set('amount', '12,50')
        ->set('date', '2026-06-05')
        ->set('counterparty', 'Bakery')
        ->call('add')
        ->assertSet('error', '');

    $tx = DB::table('transactions')->where('user_id', $this->user->id)->where('source_format', 'manual')->first();
    expect($tx)->not->toBeNull();
    expect((int) $tx->settled_amount_minor)->toBe(-1250);          // expense → negative €12.50
    expect($tx->counterparty_name)->toBe('Bakery');
    expect($tx->type)->toBe('expense');

    // Hung off the synthetic Cash account + manual import run.
    expect(DB::table('accounts')->where('user_id', $this->user->id)->where('kind', 'cash')->count())->toBe(1);
    expect(DB::table('import_runs')->where('user_id', $this->user->id)->where('source_format', 'manual')->count())->toBe(1);
});

it('reuses one cash account and one manual run across entries', function (): void {
    $component = Livewire::actingAs($this->user)->test(CashBookPage::class);
    foreach (['3,00', '4,50', '5,00'] as $amount) {
        $component->set('amount', $amount)->set('counterparty', 'Coffee')->set('date', '2026-06-05')->call('add');
    }

    expect(DB::table('transactions')->where('user_id', $this->user->id)->where('source_format', 'manual')->count())->toBe(3);
    expect(DB::table('accounts')->where('user_id', $this->user->id)->where('kind', 'cash')->count())->toBe(1);
    expect(DB::table('import_runs')->where('user_id', $this->user->id)->where('source_format', 'manual')->count())->toBe(1);
});

it('records income as a positive amount', function (): void {
    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('direction', 'income')
        ->set('amount', '20')
        ->set('date', '2026-06-05')
        ->set('counterparty', 'Refund')
        ->call('add');

    $tx = DB::table('transactions')->where('user_id', $this->user->id)->where('source_format', 'manual')->first();
    expect((int) $tx->settled_amount_minor)->toBe(2000);
    expect($tx->type)->toBe('income');
});

it('rejects a zero / blank amount', function (): void {
    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('amount', '0')
        ->call('add')
        ->assertSet('error', 'Enter an amount greater than zero.');

    expect(DB::table('transactions')->where('user_id', $this->user->id)->where('source_format', 'manual')->count())->toBe(0);
});

it('deletes only the user\'s own manual entry', function (): void {
    $component = Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('amount', '9,99')->set('counterparty', 'Market')->set('date', '2026-06-05')->call('add');

    $id = (int) DB::table('transactions')->where('user_id', $this->user->id)->where('source_format', 'manual')->value('id');

    // Two steps: deleting fired on the first tap with no confirmation and no
    // undo, on a row whose only other control is an amount.
    $component->call('confirmDelete', $id)->call('delete', $id);

    expect(DB::table('transactions')->where('id', $id)->exists())->toBeFalse();
});

it('does not delete an entry nobody was asked about', function (): void {
    $component = Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('amount', '9,99')->set('counterparty', 'Market')->set('date', '2026-06-05')->call('add');

    $id = (int) DB::table('transactions')->where('user_id', $this->user->id)->where('source_format', 'manual')->value('id');

    // A delete arriving for anything other than the entry the confirm strip is
    // open for is a client that skipped the question.
    $component->call('delete', $id);

    expect(DB::table('transactions')->where('id', $id)->exists())
        ->toBeTrue('an unconfirmed delete went through');
});

it('records two identical same-day entries without silently dropping the second', function (): void {
    $component = Livewire::actingAs($this->user)->test(CashBookPage::class);

    // add() resets the form, so re-enter the same values for the second entry.
    $component->set('amount', '3,00')->set('counterparty', 'Coffee')->set('date', '2026-06-05')->call('add');
    $component->set('amount', '3,00')->set('counterparty', 'Coffee')->set('date', '2026-06-05')->call('add');

    // A per-day bookedAt collided on the fingerprint index and dropped one.
    expect(DB::table('transactions')->where('user_id', $this->user->id)->where('source_format', 'manual')->count())->toBe(2);
});

it('drops a foreign (cross-user) category id rather than attaching it', function (): void {
    $other = User::query()->create(['username' => 'cb-other', 'password' => 'fixture-password-12chars', 'period_start_day' => 1]);
    $foreignCategoryId = DB::table('categories')->insertGetId([
        'user_id' => $other->id, 'name' => 'Private', 'slug' => 'private-x', 'kind' => 'expense',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    Livewire::actingAs($this->user)->test(CashBookPage::class)
        ->set('amount', '5,00')->set('counterparty', 'Shop')->set('date', '2026-06-05')
        ->set('categoryId', $foreignCategoryId) // tampered client payload
        ->call('add');

    $tx = DB::table('transactions')->where('user_id', $this->user->id)->where('source_format', 'manual')->first();
    expect($tx->category_id)->toBeNull(); // foreign category never attached
});

// CarbonImmutable::parse('') returns NOW rather than throwing, so a cleared
// date field fell through the catch and booked the entry today. SafeDate
// rejects the empty string before parsing.
it('refuses a cleared date instead of silently booking the entry today', function (): void {
    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('direction', 'expense')
        ->set('amount', '9,99')
        ->set('date', '')
        ->set('counterparty', 'Nowhere')
        ->call('add')
        ->assertSet('error', Lang::get('cashbook::cash-book.errors.invalid_date'));

    expect(DB::table('transactions')->where('user_id', $this->user->id)->where('counterparty_name', 'Nowhere')->exists())
        ->toBeFalse('a cleared date must not write a transaction dated today');
});
