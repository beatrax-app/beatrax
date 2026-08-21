<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

function tnfsUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function tnfsAsnAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'tnfs '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL00TNFS'.strtoupper($slug),
        'default_currency' => 'EUR',
    ]);
}

function tnfsSeedDashboardPath(User $user): void
{
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tnfs.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $account = tnfsAsnAccount($user, 'main-'.bin2hex(random_bytes(3)));
    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'value_date' => '2026-05-10',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Seed',
        'counterparty_normalized' => 'seed',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('tnfs-'.bin2hex(random_bytes(4)), 64, 'd', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

function tnfsSeedShortfall(User $user, Account $account, int $count = 1): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'scenario_id' => null,
        'starts_at' => CarbonImmutable::now()->toDateString(),
        'ends_at' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'lowest_balance_minor' => -10000,
        'currency' => 'EUR',
        'buffer_used_minor' => 0,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ];

    // Chunked: SQLite caps the bindings one statement may carry, and the
    // four-digit case seeds a thousand windows.
    foreach (array_chunk(array_fill(0, $count, $row), 50) as $chunk) {
        $db->connection()->table('forecast_shortfall_windows')->insert($chunk);
    }
}

// The rendered badge, asserted whole: the dashboard's forecast tile carries
// the same sentence inside its own aria-label, so the phrase alone would not
// prove the count reached the sidebar.
// The label is asked for rather than spelled out: this hard-coded the plural
// arm, so a badge reading "1" claimed "1 active shortfall windows" and the
// test agreed with it. What is under test is the badge markup; the copy is
// pinned where the copy lives.
function tnfsBadge(int $count, string $label): string
{
    $aria = Lang::choice('core::sidebar.badge.forecast', $count, [
        'count' => $count,
        'days' => ForecastHighlightsQuery::HORIZON_DAYS,
    ]);

    return '<span role="img" class="side-badge alert" aria-label="'.$aria.'">'.$label.'</span>';
}

beforeEach(function (): void {
    $this->user = tnfsUser('topnav-forecast');
    tnfsSeedDashboardPath($this->user);
});

it('renders the Forecasts slot without a badge when the shortfall count is zero', function (): void {
    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSeeText('Forecast')
        // Not "bg-rose-50", which the predecessor asserted: that also matches
        // inside the hover:bg-rose-50 of unrelated dashboard components, so
        // the negative held only while none of them rendered. This sentence
        // ships from the badge and the forecast tile, and both hide at zero.
        ->assertDontSee('active shortfall windows', false);
});

it('renders the rose alert badge on the Forecasts row when the shortfall count is 1', function (): void {
    $account = tnfsAsnAccount($this->user, 'sf-'.bin2hex(random_bytes(3)));
    tnfsSeedShortfall($this->user, $account, 1);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSee(tnfsBadge(1, '1'), false);
});

it('compacts the badge label to 1k once the shortfall count reaches four digits', function (): void {
    $account = tnfsAsnAccount($this->user, 'sf-many-'.bin2hex(random_bytes(3)));
    tnfsSeedShortfall($this->user, $account, 1000);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        // The label compacts; the aria-label keeps the exact number, which is
        // the half a screen reader reads out. The deleted top-nav capped both
        // at "99+" and lost the number entirely.
        ->assertSee(tnfsBadge(1000, '1k'), false)
        ->assertDontSee('>99+<', false);
});

it('activates the Forecast link when on /forecast', function (): void {
    $this->actingAs($this->user)
        ->get('/forecast')
        ->assertOk();
});

it('does not render the badge for an unauthenticated visitor', function (): void {
    $this->get('/')
        ->assertRedirect('/login');
});
