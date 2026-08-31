<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Shell\Internal\Http\Livewire\Dashboard;

// A statement covering February to April, imported in August, leaves the
// dashboard reading zeros on every tile. The figures are right and the reader
// has just been told the import worked, so the screen says the opposite of what
// happened -- and the only way across is the previous-period glyph pressed an
// unknown number of times.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 09:00:00'));
    DB::table('currencies')->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function strandedReader(int $periodStartDay = 1): User
{
    return User::query()->create([
        'username' => 'stranded-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => $periodStartDay,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
}

function recordPostedOn(User $user, string $postedAt, string $counterparty): Transaction
{
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'stranded-'.$user->id],
        [
            'name' => 'ASN stranded',
            'kind' => 'bank',
            'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
            'default_currency' => 'EUR',
        ],
    );

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/stranded.xml',
        'sha256' => hash('sha256', 'stranded-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => $counterparty,
        'counterparty_normalized' => mb_strtolower($counterparty),
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('str-'.bin2hex(random_bytes(8)), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('offers the latest period with records to a reader whose period has none', function (): void {
    $user = strandedReader();
    $this->actingAs($user);
    recordPostedOn($user, '2026-02-11', 'February Vendor');
    recordPostedOn($user, '2026-04-17', 'April Vendor');

    Livewire::test(Dashboard::class)
        ->assertSee(Lang::get('core::dashboard.jump_to_records.body'))
        ->assertSee(Lang::get('core::dashboard.jump_to_records.action', ['period' => 'April 2026']))
        ->call('goToLatestPeriod')
        ->assertSet('periodStartStr', '2026-04-01');
});

it('lands on the latest populated period in one press, never the earliest', function (): void {
    $user = strandedReader();
    $this->actingAs($user);
    recordPostedOn($user, '2026-02-11', 'February Vendor');
    recordPostedOn($user, '2026-03-03', 'March Vendor');
    recordPostedOn($user, '2026-04-17', 'April Vendor');

    Livewire::test(Dashboard::class)
        ->call('goToLatestPeriod')
        ->assertSet('periodStartStr', '2026-04-01')
        ->assertDontSee(Lang::get('core::dashboard.jump_to_records.body'));
});

it('turns the reader back forward when they have paged before their earliest record', function (): void {
    $user = strandedReader();
    $this->actingAs($user);
    recordPostedOn($user, '2026-04-17', 'April Vendor');

    Livewire::test(Dashboard::class)
        ->set('periodStartStr', '2020-01-01')
        ->assertSee(Lang::get('core::dashboard.jump_to_records.body'))
        ->call('goToLatestPeriod')
        ->assertSet('periodStartStr', '2026-04-01');
});

it('takes the target off the readers own period calendar, not the first of a month', function (): void {
    $user = strandedReader(periodStartDay: 25);
    $this->actingAs($user);
    recordPostedOn($user, '2026-04-17', 'April Vendor');

    // 17 April falls in the period that opened on 25 March, not the one that
    // opens on 25 April.
    Livewire::test(Dashboard::class)
        ->call('goToLatestPeriod')
        ->assertSet('periodStartStr', '2026-03-25');
});

it('offers nothing to a reader who has imported nothing', function (): void {
    $user = strandedReader();
    $this->actingAs($user);

    Livewire::test(Dashboard::class)
        ->assertDontSee(Lang::get('core::dashboard.jump_to_records.body'))
        ->call('goToLatestPeriod')
        ->assertSet('periodStartStr', null);
});

it('says nothing at all about another households records', function (): void {
    $housemate = strandedReader();
    recordPostedOn($housemate, '2026-06-09', 'Housemate Vendor');

    $reader = strandedReader();
    $this->actingAs($reader);

    Livewire::test(Dashboard::class)
        ->assertDontSee(Lang::get('core::dashboard.jump_to_records.body'))
        ->call('goToLatestPeriod')
        ->assertSet('periodStartStr', null);
});

it('dates the jump from the readers own latest record, never a housemates', function (): void {
    $housemate = strandedReader();
    recordPostedOn($housemate, '2026-06-09', 'Housemate Vendor');

    $reader = strandedReader();
    $this->actingAs($reader);
    recordPostedOn($reader, '2026-02-11', 'February Vendor');

    Livewire::test(Dashboard::class)
        ->assertSee(Lang::get('core::dashboard.jump_to_records.action', ['period' => 'February 2026']))
        ->call('goToLatestPeriod')
        ->assertSet('periodStartStr', '2026-02-01');
});

it('does not let a housemates busy period pass for the readers own', function (): void {
    $housemate = strandedReader();
    recordPostedOn($housemate, '2026-08-04', 'Housemate Vendor');

    $reader = strandedReader();
    $this->actingAs($reader);
    recordPostedOn($reader, '2026-02-11', 'February Vendor');

    // Read unscoped, the housemate's August row answers "this period has
    // records" and the offer disappears — the absence itself dating a
    // transaction in another household.
    Livewire::test(Dashboard::class)
        ->assertSee(Lang::get('core::dashboard.jump_to_records.action', ['period' => 'February 2026']))
        ->call('goToLatestPeriod')
        ->assertSet('periodStartStr', '2026-02-01');
});

it('leaves a populated period alone', function (): void {
    $user = strandedReader();
    $this->actingAs($user);
    recordPostedOn($user, '2026-08-04', 'August Vendor');
    recordPostedOn($user, '2026-02-11', 'February Vendor');

    Livewire::test(Dashboard::class)
        ->assertDontSee(Lang::get('core::dashboard.jump_to_records.body'));
});
