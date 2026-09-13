<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Enums\ReportDimension;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Enums\ReportMetricSelection;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

uses(RefreshDatabase::class);

// Walked at 1440x900 and 390x844: with Weekly pressed and the buckets correctly
// grouped as 1 Sep / 8 Sep / 15 Sep / 22 Sep / 29 Sep, the first column header
// read MONTH. ReportGroupHeading answers Period for a time bucket and Period
// read the `group_header.month` key whatever the reader had chosen, so the
// heading disagreed with every row beneath it in all 26 languages.

function weeklyHeadingUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'weekhead-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function weeklyHeadingFirstColumn(string $html): string
{
    return RenderedMarkup::of($html)->firstOrFail('table th')->text();
}

function weeklyHeadingBuilder(string $granularity, string $metric = ReportMetricSelection::Spend->value): string
{
    return Livewire::test(ReportBuilder::class)
        ->set('metric', $metric)
        ->set('dimension', ReportDimension::TimeBucket->value)
        ->set('granularity', $granularity)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-09-01')
        ->set('customTo', '2026-09-30')
        ->html();
}

// Spend over an empty ledger draws the empty state and no table at all, so
// every heading question below would be asked of markup that has none.
function weeklyHeadingLedger(User $user): void
{
    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'weekhead-'.$user->id,
        'kind' => 'bank',
        'iban' => 'NL00WEEK'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);

    $connection = app(DatabaseManager::class)->connection();

    $runId = $connection->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/weekhead-'.$user->id.'.csv',
        'sha256' => hash('sha256', 'weekhead-'.$user->id),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rows = [];
    foreach ([2, 9, 16, 23, 30] as $day) {
        $date = CarbonImmutable::create(2026, 9, $day)->toDateString();
        $rows[] = [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_run_id' => $runId,
            'fingerprint' => hash('sha256', 'weekhead-'.$user->id.'-'.$day),
            'posted_at' => $date,
            'booked_at' => $date.' 00:00:00',
            'value_date' => $date,
            'amount_minor' => -100 * $day,
            'currency' => 'EUR',
            'settled_amount_minor' => -100 * $day,
            'settled_currency' => 'EUR',
            'counterparty_normalized' => 'weekhead',
            'counterparty_name' => 'Merchant',
            'normalization_version' => 1,
            'description' => 'weekhead row '.$day,
            'type' => 'expense',
            'source_format' => 'asn-csv',
            'source_row_index' => $day,
            'fingerprint_version' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    $connection->table('transactions')->insert($rows);
}

beforeEach(function (): void {
    $user = weeklyHeadingUser();
    weeklyHeadingLedger($user);
    $this->actingAs($user);
});

it('heads a weekly report with weeks', function (): void {
    expect(weeklyHeadingFirstColumn(weeklyHeadingBuilder(ReportGranularity::Weekly->value)))
        ->toBe(Lang::get('reports::builder.group_header.week'));
});

// The other half of the same question. A heading hard-coded the other way round
// is the same defect wearing the other word.
it('still heads a monthly report with months', function (): void {
    expect(weeklyHeadingFirstColumn(weeklyHeadingBuilder(ReportGranularity::Monthly->value)))
        ->toBe(Lang::get('reports::builder.group_header.month'));
});

// net_worth hides the dimension picker and keeps whatever the URL last said,
// so it reaches the same heading by a different route and asks the same
// question of the granularity.
it('heads a weekly net-worth series with weeks too', function (): void {
    expect(weeklyHeadingFirstColumn(weeklyHeadingBuilder(ReportGranularity::Weekly->value, ReportMetricSelection::NetWorth->value)))
        ->toBe(Lang::get('reports::builder.group_header.week'));
});

// The positive control: the two headings have to be different words, or every
// assertion above passes against one string used for both.
it('has a different word for a week and for a month', function (): void {
    expect(Lang::get('reports::builder.group_header.week'))
        ->not->toBe(Lang::get('reports::builder.group_header.month'));
});
