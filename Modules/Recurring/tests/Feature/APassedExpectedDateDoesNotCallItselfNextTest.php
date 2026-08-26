<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

// Found on an iPhone: /recurring read "Albert Heijn — Monthly · 02 Aug 2026" on
// 26 August, calling a day three weeks gone the next one. The date is the one
// the cadence rule asks for; the word in front of it was the wrong word.
function overdueUser(): User
{
    return User::query()->create([
        'username' => 'overdue-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function overdueSeries(User $user, string $name, string $nextExpected, ?string $lastSeen): RecurringSeries
{
    $series = RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -11784,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -11784,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense::'.$name.'::eur::monthly',
        'next_expected_at' => $nextExpected,
        'next_expected_confidence_low' => false,
    ]);

    if ($lastSeen !== null) {
        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'acct '.$name,
            'slug' => 'acct-'.$name,
            'kind' => 'bank',
            'iban' => 'NL00OVDU'.str_pad(substr($name, 0, 8), 10, '0', STR_PAD_RIGHT),
            'default_currency' => 'EUR',
        ]);
        $run = ImportRun::query()->create([
            'user_id' => $user->id,
            'source_format' => 'asn-csv',
            'raw_file_path' => '/tmp/overdue.csv',
            'sha256' => str_pad($name, 64, 'z', STR_PAD_LEFT),
            'uploaded_at' => CarbonImmutable::parse('2026-08-26 00:00:00'),
            'status' => 'previewed',
        ]);
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $transactionId = $db->connection()->table('transactions')->insertGetId([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'posted_at' => $lastSeen,
            'booked_at' => $lastSeen.' 12:00:00',
            'value_date' => $lastSeen,
            'amount_minor' => -11784,
            'currency' => 'EUR',
            'settled_amount_minor' => -11784,
            'settled_currency' => 'EUR',
            'counterparty_name' => $name,
            'counterparty_normalized' => $name,
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => 1,
            'fingerprint' => str_pad($name, 64, 'q', STR_PAD_LEFT),
            'fingerprint_version' => 3,
            'created_at' => '2026-08-26 12:00:00',
            'updated_at' => '2026-08-26 12:00:00',
        ]);
        RecurringSeriesOccurrence::query()->create([
            'user_id' => $user->id,
            'recurring_series_id' => $series->id,
            'transaction_id' => $transactionId,
            'observed_at' => $lastSeen,
            'observed_amount_minor' => -11784,
            'observed_currency' => 'EUR',
        ]);
    }

    return $series;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-26 12:00:00');
    $this->user = overdueUser();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('calls a passed expected date overdue rather than next', function (): void {
    overdueSeries($this->user, 'albertheijn', '2026-08-02', '2026-07-25');

    $content = $this->actingAs($this->user)->get(route('recurring.index'))->getContent() ?: '';

    expect($content)->toContain('Overdue')
        ->and($content)->toContain('02 Aug 2026');
});

it('still calls a future expected date next', function (): void {
    overdueSeries($this->user, 'sportschool', '2026-09-22', '2026-08-19');

    $content = $this->actingAs($this->user)->get(route('recurring.index'))->getContent() ?: '';

    expect($content)->toContain('22 Sep 2026')
        ->and($content)->not->toContain('Overdue');
});

// A charge that landed after the expected day is not late; the detector has
// simply not re-swept the date forward yet, which is the state the reminder job
// already guards against. Calling that overdue would be a fresh false claim.
it('does not call a series overdue when a charge landed after the expected day', function (): void {
    overdueSeries($this->user, 'coolblue', '2026-08-02', '2026-08-14');

    $content = $this->actingAs($this->user)->get(route('recurring.index'))->getContent() ?: '';

    expect($content)->toContain('02 Aug 2026')
        ->and($content)->not->toContain('Overdue');
});
