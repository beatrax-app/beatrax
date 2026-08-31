<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Chains\Models\CardStatement;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\ValueObjects\Money;

uses(RefreshDatabase::class);

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

function fhtAsnAccount(User $user, string $slug, string $currency = 'EUR'): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'fht '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL57FHT'.strtoupper($slug),
        'default_currency' => $currency,
    ]);
}

/**
 * @param  array<int, array{currency: string, lowest: int}>  $byAccount
 */
function fhtRunDipping(DatabaseManager $db, User $user, array $byAccount): void
{
    $accounts = [];
    foreach ($byAccount as $accountId => $spec) {
        $accounts[(string) $accountId] = [
            'account_id' => $accountId,
            'account_name' => 'fht '.$accountId,
            'default_currency' => $spec['currency'],
            'today_balance_minor' => 0,
            'anchor_source' => 'user_input_opening_balance',
            'points' => [
                ['date' => '2026-05-19', 'low_minor' => 0, 'point_minor' => 0, 'high_minor' => 0, 'currency' => $spec['currency']],
                ['date' => '2026-05-20', 'low_minor' => $spec['lowest'], 'point_minor' => $spec['lowest'], 'high_minor' => $spec['lowest'], 'currency' => $spec['currency']],
            ],
        ];
    }

    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $user->id,
        'scenario_id' => null,
        'horizon_days' => 30,
        'status' => 'complete',
        'result_json' => json_encode(['as_of' => '2026-05-19', 'horizon_days' => 30, 'accounts' => $accounts]),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
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

it('renders the next ICS settlement amount when one is upcoming', function (): void {
    // Before the statement's own due date, which is what "upcoming" means:
    // read at today's date this fixture is months overdue, and the tile now
    // says so.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 09:00:00'));

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

    CarbonImmutable::setTestNow();
});

it('hides the Forecast highlights tile when no settlement is upcoming AND no projection exists', function (): void {
    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertDontSeeText('Next ICS settlement');
});

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
        'horizon_days' => ForecastHighlightsQuery::TILE_HORIZON,
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
        'horizon_days' => ForecastHighlightsQuery::TILE_HORIZON,
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

it('links the Forecast highlights tile to /forecast', function (): void {
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
                        ['date' => '2026-05-25', 'low_minor' => -12345, 'point_minor' => -12345, 'high_minor' => -12345, 'currency' => 'EUR'],
                    ],
                ],
            ],
        ]),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $response = $this->actingAs($this->user)->get('/');
    $response->assertOk();
    $body = $response->getContent();
    expect($body)->not->toBeFalse();
    // An earlier abs() in the tile rendered the figure unsigned and hid the
    // overdraft, so the sign is the assertion — asserted together with the
    // symbol, because where the sign sits relative to it is the reader's
    // convention too, and English puts it first where Dutch writes "€ -123,45".
    expect($body)->toContain('-€123.45');
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
        'horizon_days' => ForecastHighlightsQuery::TILE_HORIZON,
        'starts_at' => CarbonImmutable::now()->toDateString(),
        'ends_at' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'lowest_balance_minor' => -50000,
        'currency' => 'EUR',
        'buffer_used_minor' => 0,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertDontSeeText('active shortfall')
        ->assertDontSeeText('999,99');
});

it('keeps the display line to the figure and moves the words beneath it', function (): void {
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

    $response = $this->actingAs($this->user)->get('/');
    $response->assertOk();
    $body = (string) $response->getContent();

    // A whole sentence in the figure slot is 34px type: on a 375pt phone the
    // tile grew to five lines and 200px, one word of "Lowest in 30 days"
    // per line, beside a net-worth card that shows label, figure, meta.
    $tile = mb_substr($body, (int) mb_strpos($body, 'Forecast highlights'));
    preg_match('/<p class="[^"]*text-3xl[^"]*"[^>]*>\s*(.*?)\s*<\/p>/s', $tile, $matches);
    expect(trim(html_entity_decode($matches[1] ?? '')))->toBe('€300.00');

    $response->assertSeeText('Lowest in 30 days');
    $response->assertSeeText('fht bank');
});

// Measured with the /settings account-currency picker set to USD on a second
// account: -USD1,100.00 is the smaller integer but the larger balance, so the
// tile named the dollar account and printed its figure under the euro sign.
// The bundled snapshot prices USD1,100.00 at EUR968.40.
it('ranks the lowest projected balance on one currency, not on the raw minor units', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $dollar = fhtAsnAccount($this->user, 'revolut', currency: 'USD');

    fhtRunDipping($db, $this->user, [
        $this->asn->id => ['currency' => 'EUR', 'lowest' => -100000],
        $dollar->id => ['currency' => 'USD', 'lowest' => -110000],
    ]);

    $dto = app(ForecastHighlightsQuery::class)->forUser($this->user);

    expect($dto->lowestProjectedAccountId)->toBe($this->asn->id);
    expect($dto->lowestProjectedBalanceMinor)->toBe(-100000);
    expect($dto->lowestProjectedBalanceCurrency)->toBe('EUR');
});

// The race runs in the reader's currency, and a conversion reads the whole
// exchange_rates table: pricing each account as its turn came round asked for
// the same pair once per account holding it.
it('prices each currency in the race once, not once per account', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $byAccount = [$this->asn->id => ['currency' => 'EUR', 'lowest' => -1000]];
    for ($i = 0; $i < 8; $i++) {
        $dollar = fhtAsnAccount($this->user, 'revolut-'.$i, currency: 'USD');
        $byAccount[$dollar->id] = ['currency' => 'USD', 'lowest' => -2000 - $i];
    }
    fhtRunDipping($db, $this->user, $byAccount);

    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        if (str_contains($query->sql, 'exchange_rates')) {
            $reads++;
        }
    });

    app(ForecastHighlightsQuery::class)->forUser($this->user);

    expect($reads)->toBe(1);
});

it('prints the lowest projected balance under the account own currency sign', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $dollar = fhtAsnAccount($this->user, 'revolut', currency: 'USD');

    fhtRunDipping($db, $this->user, [$dollar->id => ['currency' => 'USD', 'lowest' => -110000]]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSee(Money::ofMinor(-110000, 'USD')->format());
});
