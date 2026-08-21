<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Tax\Internal\Http\Livewire\TaxPage;

uses(RefreshDatabase::class);

function taxPageUser(string $username = 'tax-page-user', bool $withCountry = true): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'test-password',
        'is_developer' => false,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // country_code is not mass-assignable, so create() would silently drop it —
    // which once masked a real blade bug. Write it the way the preference does.
    if ($withCountry) {
        app(DatabaseManager::class)->connection()
            ->table('users')
            ->where('id', $user->id)
            ->update(['country_code' => 'nl']);
    }

    return $user;
}

function taxPageTaggedTransaction(
    DatabaseManager $db,
    int $userId,
    string $counterparty,
    int $year,
    string $categoryName,
    int $amountMinor = -5000,
    ?string $note = null,
    ?int $overrideYear = null,
): int {
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Test Account '.$suffix,
        'slug' => 'test-acct-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/test-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create a counterparty record so the TaxYearQuery join returns a display_name.
    $cpId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => 'test-cp-'.$suffix,
        'display_name' => $counterparty,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tx-'.$suffix),
        'posted_at' => "{$year}-06-15",
        'booked_at' => "{$year}-06-15 00:00:00",
        'value_date' => "{$year}-06-15",
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => strtolower($counterparty),
        'counterparty_name' => $counterparty,
        'counterparty_id' => $cpId,
        'normalization_version' => 1,
        'description' => 'Test transaction '.$suffix,
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $existingCat = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $userId)
        ->where('name', $categoryName)
        ->first(['id']);

    if ($existingCat !== null) {
        $catId = (int) $existingCat->id;
    } else {
        $catId = $db->connection()->table('tax_deduction_categories')->insertGetId([
            'user_id' => $userId,
            'name' => $categoryName,
            'short_name' => substr($categoryName, 0, 12),
            'corpus_key' => 'test_'.$suffix,
            'status' => 'active',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $db->connection()->table('tax_transaction_tags')->insertGetId([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'deduction_category_id' => $catId,
        'note' => $note,
        'tax_year_override' => $overrideYear,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('renders the /tax page for an authenticated user', function (): void {
    $user = taxPageUser();

    $this->actingAs($user)
        ->get('/tax')
        ->assertOk();
});

it('redirects unauthenticated requests to the login page', function (): void {
    $response = $this->get('/tax');

    $response->assertRedirect('/login');
});

it('resolves tax.index route to TaxPage class (not a closure)', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes());
    $taxRoute = $routes->firstWhere(fn ($r) => $r->getName() === 'tax.index');

    expect($taxRoute)->not->toBeNull();
    expect($taxRoute->getAction('uses'))->toContain('TaxPage');
});

it('applies seasonal default — February → previous year (month <= 4)', function (): void {
    $user = taxPageUser(username: 'seasonal-feb');

    $clock = Mockery::mock(Clock::class);
    $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 2, 15));
    app()->instance(Clock::class, $clock);

    $component = Livewire::actingAs($user)->test(TaxPage::class);

    // February 2026 → previous year = 2025
    expect($component->get('year'))->toBe(2025);
});

it('applies seasonal default — August → current year (month > 4)', function (): void {
    $user = taxPageUser(username: 'seasonal-aug');

    $clock = Mockery::mock(Clock::class);
    $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 8, 15));
    app()->instance(Clock::class, $clock);

    $component = Livewire::actingAs($user)->test(TaxPage::class);

    // August 2026 → current year = 2026
    expect($component->get('year'))->toBe(2026);
});

it('shows tagged transactions grouped by deduction category', function (): void {
    $user = taxPageUser(username: 'grouped-user');
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $clock = Mockery::mock(Clock::class);
    $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 8, 1));
    app()->instance(Clock::class, $clock);

    taxPageTaggedTransaction($db, $user->id, 'Apotheek BV', 2026, 'Healthcare', -4500);
    taxPageTaggedTransaction($db, $user->id, 'Dentist BV', 2026, 'Healthcare', -12000);

    $component = Livewire::actingAs($user)->test(TaxPage::class);

    $component->assertSee('Healthcare');
    $component->assertSee('Apotheek BV');
    $component->assertSee('Dentist BV');
});

it('changes results when the year switcher is used', function (): void {
    $user = taxPageUser(username: 'year-switch-user');
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $clock = Mockery::mock(Clock::class);
    $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 8, 1));
    app()->instance(Clock::class, $clock);

    taxPageTaggedTransaction($db, $user->id, 'Past Year Merchant BV', 2025, 'Education', -9900);
    taxPageTaggedTransaction($db, $user->id, 'Current Year Merchant BV', 2026, 'Transport', -3300);

    $component = Livewire::actingAs($user)->test(TaxPage::class);

    // Default: 2026 (August)
    $component->assertSee('Current Year Merchant BV')
        ->assertDontSee('Past Year Merchant BV');

    $component->set('year', 2025);
    $component->assertSee('Past Year Merchant BV')
        ->assertDontSee('Current Year Merchant BV');
});

it('shows the empty-year calm note when no tagged transactions exist for the year', function (): void {
    $user = taxPageUser(username: 'empty-year-user');
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $clock = Mockery::mock(Clock::class);
    $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 8, 1));
    app()->instance(Clock::class, $clock);

    // A tag in another year, so the "other years" count has something to show.
    taxPageTaggedTransaction($db, $user->id, 'Old Merchant BV', 2025, 'Zorgkosten', -1500);

    $component = Livewire::actingAs($user)->test(TaxPage::class);

    $component->assertSee('Nothing tagged for 2026 yet.');
    $component->assertSee('tagged items in other years');
});

it('exportCsv returns a StreamedResponse with the correct filename and content type', function (): void {
    $user = taxPageUser(username: 'export-csv-user');
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $clock = Mockery::mock(Clock::class);
    $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 8, 1));
    app()->instance(Clock::class, $clock);

    taxPageTaggedTransaction($db, $user->id, 'Pharmacy BV', 2026, 'Healthcare', -3000);

    $component = Livewire::actingAs($user)->test(TaxPage::class);

    $response = $component->call('exportCsv');

    expect($response)->not->toBeNull();
    expect($component->instance()->year)->toBe(2026);
});

it('exportPdf returns a StreamedResponse with the correct filename and content type', function (): void {
    $user = taxPageUser(username: 'export-pdf-user');
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $clock = Mockery::mock(Clock::class);
    $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 8, 1));
    app()->instance(Clock::class, $clock);

    taxPageTaggedTransaction($db, $user->id, 'Physio BV', 2026, 'Healthcare', -6000);

    $component = Livewire::actingAs($user)->test(TaxPage::class);

    $response = $component->call('exportPdf');

    expect($response)->not->toBeNull();
    expect($component->instance()->year)->toBe(2026);
});

it('exportCsv returns a response for an unauthenticated component instead of throwing', function (): void {
    $response = Livewire::test(TaxPage::class)->call('exportCsv');

    expect($response)->not->toBeNull();
});

it('exportPdf returns a response for an unauthenticated component instead of throwing', function (): void {
    $response = Livewire::test(TaxPage::class)->call('exportPdf');

    expect($response)->not->toBeNull();
});

it('asks for the tax country above the figures rather than instead of them', function (): void {
    $user = taxPageUser(username: 'no-country-user', withCountry: false);

    $this->actingAs($user)
        ->get('/tax')
        ->assertOk()
        ->assertSee('Which country do you file taxes in?')
        // A blade directive that does not exist must not leak into the output.
        ->assertDontSee('@return')
        // The prompt used to replace the whole page, so a dashboard card
        // promising tagged items led to a screen showing none of them.
        ->assertSee('Total deductions');
});
