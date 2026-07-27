<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

/*
 * Top-nav "Forecast" slot composer tests.
 *
 * The ForecastingServiceProvider attaches a `forecastShortfallCount`
 * integer to `core::livewire.top-nav` via the View Factory contract
 * (NEVER the `view()` global helper). The slot renders a rose-50
 * ↘-glyph pill when the user has at least one active shortfall.
 */

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
        'kind' => 'asn',
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
    for ($i = 0; $i < $count; $i++) {
        $db->connection()->table('forecast_shortfall_windows')->insert([
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
        ]);
    }
}

beforeEach(function (): void {
    $this->user = tnfsUser('topnav-forecast');
    tnfsSeedDashboardPath($this->user);
});

it('renders the Forecast slot without a badge when forecastShortfallCount=0', function (): void {
    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSeeText('Forecast')
        ->assertDontSee('bg-rose-50');
})->todo('16-01 replaced the top-nav with the app sidebar, so the rose-50 pill this asserts the absence of no longer ships at all — the same reason the two assertions below are deferred. It also asserts too broadly to be revived as-is: "bg-rose-50" matches inside the "hover:bg-rose-50" carried by unrelated components on the dashboard, so the negative held only while none of them rendered. The follow-up plan that re-introduces the .side-badge.alert pill gives this a marker specific to the badge.');

it('renders the rose-50 pill with the ↘ glyph when shortfallCount >= 1', function (): void {
    $account = tnfsAsnAccount($this->user, 'sf-'.bin2hex(random_bytes(3)));
    tnfsSeedShortfall($this->user, $account, 1);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSee('bg-rose-50')
        ->assertSee('text-rose-700')
        ->assertSee('↘', escape: false);
})->todo('16-01 replaced the top-nav with the app sidebar. The Forecast rose-50 pill + ↘ glyph chrome no longer ships in the new sidebar markup; a follow-up plan re-wires the forecast composer onto core::livewire.app-sidebar and re-introduces the .side-badge.alert pill, at which point this assertion is updated to match the new chrome.');

it('caps the badge label at 99+ for very large shortfall counts', function (): void {
    $account = tnfsAsnAccount($this->user, 'sf-many-'.bin2hex(random_bytes(3)));
    // Seed 100 distinct shortfall windows.
    tnfsSeedShortfall($this->user, $account, 100);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSee('99+');
})->todo('16-01 replaced the top-nav with the app sidebar. The Forecast 99+ badge label no longer ships in the new sidebar markup; a follow-up plan re-wires the forecast composer onto core::livewire.app-sidebar and re-introduces the badge slot, at which point this assertion is updated to match the new chrome.');

it('activates the Forecast link when on /forecast', function (): void {
    $this->actingAs($this->user)
        ->get('/forecast')
        ->assertOk();
});

it('does not render the badge for an unauthenticated visitor', function (): void {
    $this->get('/')
        ->assertRedirect('/login');
});
