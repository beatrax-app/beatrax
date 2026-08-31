<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Calendar\Internal\Dto\CalendarDayDto;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;

uses(RefreshDatabase::class);

// Read on an iPhone, May 2026. The day panel prints a start-of-day figure, the
// day's payments, and an end-of-day figure, and a reader reads that as
// arithmetic. The balance line sums the spendable kinds; the entry list is
// drawn from every visible account. On 5 May a card charge sat between two
// identical figures with nothing on the panel saying the balance could not
// move for it, and the screen read as EUR80 wrong.

const BNC_TODAY = '2026-05-29';

const BNC_CARD_DAY = '2026-05-05';

const BNC_BANK_DAY = '2026-05-08';

const BNC_OPENING_MINOR = 191_000;

const BNC_CARD_MINOR = -8_000;

const BNC_BANK_MINOR = -1_299;

const BNC_CHECKING = 'iPhone ASN Betaalrekening';

const BNC_SAVINGS = 'ASN Spaarrekening';

const BNC_CARD = 'iPhone ICS Card';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(BNC_TODAY.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'bnc-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
    $this->checkingId = bncAccount($this->db, (int) $this->user->id, BNC_CHECKING, AccountKind::Bank->value, BNC_OPENING_MINOR);
    $this->savingsId = bncAccount($this->db, (int) $this->user->id, BNC_SAVINGS, AccountKind::Bank->value, 0);
    $this->cardId = bncAccount($this->db, (int) $this->user->id, BNC_CARD, AccountKind::IcsCard->value, 0);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function bncAccount(DatabaseManager $db, int $userId, string $name, string $kind, int $startingMinor): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'bnc-'.$hex,
        'kind' => $kind,
        'iban' => 'NL00BNC'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => $startingMinor,
        'starting_balance_date' => '2026-04-01',
        'created_at' => '2026-04-01 00:00:00',
        'updated_at' => '2026-04-01 00:00:00',
    ]);
}

function bncRow(DatabaseManager $db, int $userId, int $accountId, string $postedAt, int $minor, string $counterparty): int
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/bnc-'.$hex.'.csv',
        'sha256' => hash('sha256', 'bnc-'.$hex),
        'uploaded_at' => BNC_TODAY.' 08:00:00',
        'status' => 'imported',
        'created_at' => BNC_TODAY.' 08:00:00',
        'updated_at' => BNC_TODAY.' 08:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'bnc-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $minor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => Str::slug($counterparty),
        'counterparty_name' => $counterparty,
        'normalization_version' => 1,
        'description' => 'bnc fixture',
        'type' => $minor < 0 ? TransactionType::Expense->value : TransactionType::Income->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => BNC_TODAY.' 08:00:00',
        'updated_at' => BNC_TODAY.' 08:00:00',
    ]);
}

function bncDayOn(User $user, string $date): CalendarDayDto
{
    foreach (app(CalendarQuery::class)->forMonth($user, 2026, 5) as $day) {
        if ($day->date->toDateString() === $date) {
            return $day;
        }
    }

    throw new RuntimeException($date.' is not a cell of the May 2026 grid');
}

// The control case, and the shape the reader believes: a bank charge is on an
// account the balance sums, so the two figures differ by exactly the row.
it('steps the day by a payment on an account it does count', function (): void {
    bncRow($this->db, (int) $this->user->id, $this->checkingId, BNC_BANK_DAY, BNC_BANK_MINOR, 'Adobe Systems Software');

    $day = bncDayOn($this->user, BNC_BANK_DAY);

    expect($day->entries)->toHaveCount(1)
        ->and($day->showsBalance())->toBeTrue()
        ->and($day->eodBalanceMinor - (int) $day->sodBalanceMinor)->toBe(BNC_BANK_MINOR);
});

// The defect as measured: the card charge is listed, and the balance is
// deliberately blind to it. That difference is not the bug; saying nothing
// about it is.
it('holds the balance still under a card charge the balance set excludes', function (): void {
    bncRow($this->db, (int) $this->user->id, $this->cardId, BNC_CARD_DAY, BNC_CARD_MINOR, 'KLM ROYAL DUTCH AIR AMSTELVEEN');

    $day = bncDayOn($this->user, BNC_CARD_DAY);

    expect($day->entries)->toHaveCount(1)
        ->and($day->entries[0]->accountName)->toBe(BNC_CARD)
        ->and($day->showsBalance())->toBeTrue()
        ->and($day->sodBalanceMinor)->toBe(BNC_OPENING_MINOR)
        ->and($day->eodBalanceMinor)->toBe(BNC_OPENING_MINOR);
});

it('names the account whose payment the day carried and the balance did not', function (): void {
    bncRow($this->db, (int) $this->user->id, $this->cardId, BNC_CARD_DAY, BNC_CARD_MINOR, 'KLM ROYAL DUTCH AIR AMSTELVEEN');

    expect(bncDayOn($this->user, BNC_CARD_DAY)->uncountedAccounts)->toBe([BNC_CARD]);
});

it('says on the panel that the balance skipped the payment it listed', function (): void {
    bncRow($this->db, (int) $this->user->id, $this->cardId, BNC_CARD_DAY, BNC_CARD_MINOR, 'KLM ROYAL DUTCH AIR AMSTELVEEN');

    $html = Livewire::actingAs($this->user)
        ->test(CalendarPage::class, ['month' => 5, 'year' => 2026])
        ->call('selectDay', BNC_CARD_DAY)
        ->html();

    expect($html)->toContain('KLM ROYAL DUTCH AIR AMSTELVEEN')
        ->and($html)->toContain(BNC_CARD.' not counted')
        ->and($html)->toContain('do not move the balance');
});

// The other half of the rule: a panel whose every row the balance summed makes
// no claim about an excluded account, because there is none to make.
it('says nothing of the kind on a day the balance counted in full', function (): void {
    bncRow($this->db, (int) $this->user->id, $this->checkingId, BNC_BANK_DAY, BNC_BANK_MINOR, 'Adobe Systems Software');

    $html = Livewire::actingAs($this->user)
        ->test(CalendarPage::class, ['month' => 5, 'year' => 2026])
        ->call('selectDay', BNC_BANK_DAY)
        ->html();

    expect(bncDayOn($this->user, BNC_BANK_DAY)->uncountedAccounts)->toBe([])
        ->and($html)->toContain('Adobe Systems Software')
        ->and($html)->not->toContain('not counted');
});

// Said once above the grid, the way an unpriced currency is: an account the
// balance excludes is excluded on every cell it appears on, and forty-two
// copies of the sentence is not forty-two facts.
it('names the excluded account once above the month grid', function (): void {
    $userId = (int) $this->user->id;
    bncRow($this->db, $userId, $this->cardId, BNC_CARD_DAY, BNC_CARD_MINOR, 'KLM ROYAL DUTCH AIR AMSTELVEEN');
    bncRow($this->db, $userId, $this->cardId, BNC_BANK_DAY, BNC_CARD_MINOR, 'Spotify Premium');

    $html = Livewire::actingAs($this->user)
        ->test(CalendarPage::class, ['month' => 5, 'year' => 2026])
        ->html();

    expect($html)->toContain(BNC_CARD.' not counted')
        ->and(substr_count($html, 'data-not-counted'))->toBe(1);
});

// A day that states no balance at all makes no arithmetic claim to correct,
// and every entry on it would otherwise be named — the noisiest possible
// answer to a panel already reading "—".
it('makes no claim about a day whose balance it never stated', function (): void {
    bncRow($this->db, (int) $this->user->id, $this->cardId, BNC_CARD_DAY, BNC_CARD_MINOR, 'KLM ROYAL DUTCH AIR AMSTELVEEN');

    $days = app(CalendarQuery::class)->forMonth($this->user, 2026, 5, null, []);

    $day = array_values(array_filter($days, fn ($d): bool => $d->date->toDateString() === BNC_CARD_DAY))[0];

    expect($day->showsBalance())->toBeFalse()
        ->and($day->entries)->toHaveCount(1)
        ->and($day->uncountedAccounts)->toBe([]);
});
