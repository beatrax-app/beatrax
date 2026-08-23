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

// A twelve-figure cash entry is a slipped finger, and it booked without a
// murmur. The bound lives in MoneyInput with every other money field's.
it('refuses an amount no cash entry could be and says why', function (): void {
    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('amount', '999999999999')
        ->call('add')
        ->assertSet('error', 'That amount is too large. Check the digits.');

    expect(DB::table('transactions')->where('user_id', $this->user->id)->where('source_format', 'manual')->count())->toBe(0);
});

// The prompt to enter something greater than zero is for a field left empty,
// which is an amount not yet given. A field holding characters that are not
// digits at all is the unreadable case, and it was the one this asserted.
it('keeps blaming the digits when the amount is not one', function (): void {
    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('amount', '🎉')
        ->call('add')
        ->assertSet('error', 'That amount could not be read. Enter it without a thousands separator and with at most two decimals, for example 1250.00.');
});

it('still asks for a figure, rather than blaming one, when the field is empty', function (): void {
    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('amount', '   ')
        ->call('add')
        ->assertSet('error', 'Enter an amount greater than zero.');
});

it('says a grouped thousands amount could not be read, not that it is smaller than zero', function (): void {
    // "1.250" is what this page prints for €1.250,00 two lines further down, so
    // a reader has every reason to type it back. It stays refused — it reads as
    // 1250 or as 1.25 — but the message has to say which problem it is.
    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('amount', '1.250')
        ->set('counterparty', 'Bakery')
        ->set('date', '2026-06-05')
        ->call('add')
        ->assertSet('error', 'That amount could not be read. Enter it without a thousands separator and with at most two decimals, for example 1250.00.');

    expect(DB::table('transactions')->where('user_id', $this->user->id)->where('source_format', 'manual')->count())->toBe(0);
});

it('writes the example with the reader\'s own decimal mark', function (): void {
    // A Dutch reader handed "1250.00" has been shown the very punctuation that
    // caused the misreading.
    app()->setLocale('nl');

    $error = Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('amount', '1.250')
        ->call('add')
        ->get('error');

    expect($error)->toBeString()
        ->toContain('1250,00')
        ->not->toContain('1250.00');
});

it('keeps the positivity message for an amount that was read and is not positive', function (): void {
    $component = Livewire::actingAs($this->user)->test(CashBookPage::class);

    foreach (['', '  ', '0', '0,00', '-5,00'] as $amount) {
        $component->set('amount', $amount)
            ->call('add')
            ->assertSet('error', 'Enter an amount greater than zero.');
    }
});

it('keeps the too-large message for an amount that was read and is too big', function (): void {
    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('amount', '1000000000,00')
        ->call('add')
        ->assertSet('error', 'That amount is too large. Check the digits.');
});

// A byte comparison files every accented name after Z, and nothing here
// asserted the order the picker is actually built with — so the collation and
// the id tiebreak beside it were both free to change unnoticed.
it('orders the category options by collated name, then by id', function (): void {
    foreach ([['Appel', 'cb-order-appel-1'], ['Appel', 'cb-order-appel-2'], ['Zebra', 'cb-order-zebra'], ['Émile', 'cb-order-emile']] as [$name, $slug]) {
        DB::table('categories')->insert([
            'user_id' => $this->user->id, 'name' => $name, 'slug' => $slug, 'kind' => 'expense',
            'name_is_default' => false,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    $categories = Livewire::actingAs($this->user)->test(CashBookPage::class)->viewData('categories');

    $mine = array_values(array_filter(
        $categories->all(),
        static fn (stdClass $row): bool => str_starts_with((string) $row->slug, 'cb-order-'),
    ));

    expect(array_map(static fn (stdClass $row): string => (string) $row->name, $mine))
        ->toBe(['Appel', 'Appel', 'Émile', 'Zebra']);

    expect((int) $mine[0]->id)->toBeLessThan((int) $mine[1]->id);
});

// The /settings account-currency picker reaches the Cash account like any
// other. Relabelled to dollars, the book kept writing euro rows, so the
// account could never come to hold the currency it names and /reconcile and
// pots — which read the account's own line — answered zero forever.
it('records into the currency the cash account is denominated in', function (): void {
    $cashAccountId = DB::table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'Cash',
        'slug' => 'cash-relabelled',
        'kind' => 'cash',
        'iban' => 'CASH'.str_pad((string) $this->user->id, 12, '0', STR_PAD_LEFT),
        'default_currency' => 'USD',
    ]);

    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('direction', 'expense')
        ->set('amount', '12,50')
        ->set('date', '2026-06-05')
        ->set('counterparty', 'Bakery')
        ->call('add')
        ->assertSet('error', '');

    $tx = DB::table('transactions')->where('user_id', $this->user->id)->where('source_format', 'manual')->first();

    expect((int) $tx->account_id)->toBe($cashAccountId);
    expect($tx->settled_currency)->toBe('USD');
    expect($tx->currency)->toBe('USD');
    expect(
        app(Modules\Ledger\Public\Services\AccountBalanceQuery::class)
            ->currentBalance($cashAccountId, $this->user)
            ->in('USD')
    )->toBe(-1250);
});

it('prints a cash entry under the sign of the currency it was recorded in', function (): void {
    DB::table('accounts')->insert([
        'user_id' => $this->user->id,
        'name' => 'Cash',
        'slug' => 'cash-relabelled',
        'kind' => 'cash',
        'iban' => 'CASH'.str_pad((string) $this->user->id, 12, '0', STR_PAD_LEFT),
        'default_currency' => 'USD',
    ]);

    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->set('amount', '12,50')
        ->set('date', '2026-06-05')
        ->set('counterparty', 'Bakery')
        ->call('add');

    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->assertSee(Modules\Ledger\Public\ValueObjects\Money::ofMinor(-1250, 'USD')->format());
});
