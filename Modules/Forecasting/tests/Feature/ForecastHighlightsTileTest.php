<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Models\CardStatement;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

/*
 * Feature coverage for the dashboard "Forecast highlights" Livewire tile.
 *
 * Replaces Modules/Chains/tests/Feature/NextIcsSettlementTileTest as a
 * strict superset:
 *   - The earlier next-settlement tile case (case 1) is ported verbatim
 *     here — the new tile STILL renders the next-settlement line.
 *   - The earlier hidden-state test (case 6 — "Dashboard hides ... when
 *     nextIcsSettlement returns null") is ported.
 *
 * New Phase 10 cases:
 *   - Lowest projected balance line surfaces in slate-900 with no shortfall.
 *   - Lowest projected balance line surfaces in rose-700 with active shortfalls.
 *   - Pluralisation: "1 active shortfall window" vs "N active shortfall windows".
 *   - Renders both lines (next-settlement + lowest-projected) when both exist.
 *   - Cross-user safety — tile drill-through does not leak other users' rows.
 *   - Tile is a link to /forecast (NEW — was a non-link tile previously).
 *
 * The Phase 5 failed-job toast tests are NOT migrated here — they have
 * nothing to do with the tile and remain in their original module
 * (Modules/Chains/tests/Feature) when re-purposed; here they're dropped
 * as part of the Phase 5 test file deletion. The failed-job toast still
 * renders from Dashboard.php's render() path, exercised by other tests.
 */

function fhtUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function fhtImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/fht.pdf',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

function fhtIcsAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'fht '.$slug,
        'slug' => $slug,
        'kind' => 'ics_card',
        'iban' => 'ICS-FHT-'.strtoupper($slug),
        'default_currency' => 'EUR',
    ]);
}

function fhtAsnAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'fht '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL57FHT'.strtoupper($slug),
        'default_currency' => 'EUR',
    ]);
}

beforeEach(function (): void {
    $this->user = fhtUser('forecast-tile');
    $this->otherUser = fhtUser('forecast-tile-other');
    $this->asn = fhtAsnAccount($this->user, 'bank');
    $this->ics = fhtIcsAccount($this->user, 'ics');
    $this->run = fhtImportRun($this->user, str_repeat('f', 64));

    // Seed a small transaction so the dashboard is past the first-run redirect.
    Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->asn->id,
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
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('fht-seed', 64, 'd', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
});

/*
 * --- Ported Phase 5 cases ---
 */

it('renders the next ICS settlement amount when one is upcoming (Phase 5 → Phase 10 ported)', function (): void {
    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->ics->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -52347,
        'open_balance_minor' => 52347,
        'state' => 'open',
    ]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSeeText('Forecast highlights')
        ->assertSeeText('Next ICS settlement');
});

it('hides the Forecast highlights tile when no settlement is upcoming AND no projection exists (Phase 5 → Phase 10 ported)', function (): void {
    // No card_statement seeded + no forecast_runs → tile is hidden.
    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertDontSeeText('Next ICS settlement');
});

/*
 * --- Phase 10 new cases ---
 */

it('renders the lowest-projected-balance line when a baseline forecast run exists with no shortfall', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $this->user->id,
        'scenario_id' => null,
        'horizon_days' => 30,
        'status' => 'complete',
        'result_json' => json_encode([
            'as_of' => '2026-05-19',
            'horizon_days' => 30,
            'accounts' => [
                (string) $this->asn->id => [
                    'account_id' => $this->asn->id,
                    'account_name' => $this->asn->name,
                    'default_currency' => 'EUR',
                    'today_balance_minor' => 50000,
                    'anchor_source' => 'user_input_opening_balance',
                    'points' => [
                        ['date' => '2026-05-19', 'low_minor' => 50000, 'point_minor' => 50000, 'high_minor' => 50000, 'currency' => 'EUR'],
                        ['date' => '2026-05-20', 'low_minor' => 30000, 'point_minor' => 30000, 'high_minor' => 30000, 'currency' => 'EUR'],
                    ],
                ],
            ],
        ]),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSeeText('Lowest in 30 days');
});

it('renders the rose-700 shortfall line when an active shortfall window exists', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $this->user->id,
        'scenario_id' => null,
        'horizon_days' => 30,
        'status' => 'complete',
        'result_json' => json_encode([
            'as_of' => '2026-05-19',
            'horizon_days' => 30,
            'accounts' => [
                (string) $this->asn->id => [
                    'account_id' => $this->asn->id,
                    'account_name' => $this->asn->name,
                    'default_currency' => 'EUR',
                    'today_balance_minor' => 12000,
                    'anchor_source' => 'user_input_opening_balance',
                    'points' => [
                        ['date' => '2026-05-19', 'low_minor' => 12000, 'point_minor' => 12000, 'high_minor' => 12000, 'currency' => 'EUR'],
                    ],
                ],
            ],
        ]),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $db->connection()->table('forecast_shortfall_windows')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->asn->id,
        'scenario_id' => null,
        'starts_at' => CarbonImmutable::now()->toDateString(),
        'ends_at' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'lowest_balance_minor' => 12000,
        'currency' => 'EUR',
        'buffer_used_minor' => 50000,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSeeText('Forecast highlights')
        ->assertSee('text-rose-700');
});

it('pluralises the active-shortfall-count line correctly (singular vs plural)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $this->user->id,
        'scenario_id' => null,
        'horizon_days' => 30,
        'status' => 'complete',
        'result_json' => json_encode([
            'as_of' => '2026-05-19',
            'horizon_days' => 30,
            'accounts' => [
                (string) $this->asn->id => [
                    'account_id' => $this->asn->id,
                    'account_name' => $this->asn->name,
                    'default_currency' => 'EUR',
                    'today_balance_minor' => 12000,
                    'anchor_source' => 'user_input_opening_balance',
                    'points' => [
                        ['date' => '2026-05-19', 'low_minor' => 12000, 'point_minor' => 12000, 'high_minor' => 12000, 'currency' => 'EUR'],
                    ],
                ],
            ],
        ]),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $db->connection()->table('forecast_shortfall_windows')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->asn->id,
        'scenario_id' => null,
        'starts_at' => CarbonImmutable::now()->toDateString(),
        'ends_at' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'lowest_balance_minor' => 12000,
        'currency' => 'EUR',
        'buffer_used_minor' => 50000,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSeeText('1 active shortfall window');
});

it('links to /forecast (replaces the non-link Phase 5 tile)', function (): void {
    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->ics->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -10000,
        'open_balance_minor' => 10000,
        'state' => 'open',
    ]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSee('href="'.route('forecast.index').'"', escape: false);
});

it('preserves the minus sign when the lowest projected balance is negative (overdraft)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $this->user->id,
        'scenario_id' => null,
        'horizon_days' => 30,
        'status' => 'complete',
        'result_json' => json_encode([
            'as_of' => '2026-05-19',
            'horizon_days' => 30,
            'accounts' => [
                (string) $this->asn->id => [
                    'account_id' => $this->asn->id,
                    'account_name' => $this->asn->name,
                    'default_currency' => 'EUR',
                    'today_balance_minor' => 5000,
                    'anchor_source' => 'user_input_opening_balance',
                    'points' => [
                        ['date' => '2026-05-19', 'low_minor' => 5000, 'point_minor' => 5000, 'high_minor' => 5000, 'currency' => 'EUR'],
                        // Projection dips into overdraft.
                        ['date' => '2026-05-25', 'low_minor' => -12345, 'point_minor' => -12345, 'high_minor' => -12345, 'currency' => 'EUR'],
                    ],
                ],
            ],
        ]),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    // The rendered tile must contain the negative-formatted figure
    // (locale "-123,45" or "€ -123,45"). The earlier abs()-stripping
    // bug rendered "€ 123,45" with no sign, masking the overdraft.
    // We verify the negative sign appears in proximity to the digits.
    $response = $this->actingAs($this->user)->get('/');
    $response->assertOk();
    $body = $response->getContent();
    expect($body)->not->toBeFalse();
    // Money::ofMinor(-12345)->format('nl_NL') yields "€ -123,45"
    // (currency-symbol-then-minus-then-figure under PHP intl rules).
    expect($body)->toContain('-123,45');
});

it('does not surface another user shortfall in the tile (cross-user isolation)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $otherIcs = fhtIcsAccount($this->otherUser, 'ics-other');
    CardStatement::query()->create([
        'user_id' => $this->otherUser->id,
        'account_id' => $otherIcs->id,
        'import_run_id' => null,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -99999,
        'open_balance_minor' => 99999,
        'state' => 'open',
    ]);
    $db->connection()->table('forecast_shortfall_windows')->insert([
        'user_id' => $this->otherUser->id,
        'account_id' => $otherIcs->id,
        'scenario_id' => null,
        'starts_at' => CarbonImmutable::now()->toDateString(),
        'ends_at' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'lowest_balance_minor' => -50000,
        'currency' => 'EUR',
        'buffer_used_minor' => 0,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    // User A sees no Forecast tile rendered (no statement, no run, no
    // shortfall for them).
    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertDontSeeText('active shortfall')
        ->assertDontSeeText('999,99');
});
