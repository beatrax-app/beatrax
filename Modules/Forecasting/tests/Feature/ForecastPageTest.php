<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function fpgUser(string $username = 'fpg'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function fpgAccount(DatabaseManager $db, int $userId, string $name): int
{
    $suffix = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'fpg-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00FPG'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-05-01',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-19 00:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = fpgUser();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders the page heading + subheading + the Adjust buffers helper link', function (): void {
    fpgAccount($this->db, $this->user->id, 'ASN Test');

    $response = $this->actingAs($this->user)->get('/forecast');

    $response->assertOk()
        ->assertSeeText('Forecast')
        ->assertSeeText('Where your balance is heading — over the next 30 to 365 days.')
        ->assertSee('Adjust buffers');
});

it('renders the horizon segmented control with 30 days selected by default', function (): void {
    fpgAccount($this->db, $this->user->id, 'ASN Test');

    $response = $this->actingAs($this->user)->get('/forecast');

    $content = (string) $response->getContent();
    expect($content)->toContain('30 days');
    expect($content)->toContain('60 days');
    expect($content)->toContain('90 days');
    expect($content)->toMatch('/aria-checked="true"[^>]*>\s*30 days/');
});

it('renders the per-account tab bar in alphabetical order', function (): void {
    fpgAccount($this->db, $this->user->id, 'Zeta Account');
    fpgAccount($this->db, $this->user->id, 'Alpha Account');
    fpgAccount($this->db, $this->user->id, 'Mid Account');

    $response = $this->actingAs($this->user)->get('/forecast');

    $content = (string) $response->getContent();
    $alphaPos = strpos($content, 'Alpha Account');
    $midPos = strpos($content, 'Mid Account');
    $zetaPos = strpos($content, 'Zeta Account');

    expect($alphaPos)->toBeInt();
    expect($midPos)->toBeInt();
    expect($zetaPos)->toBeInt();
    expect($alphaPos)->toBeLessThan((int) $midPos);
    expect($midPos)->toBeLessThan((int) $zetaPos);
});

it('renders the baseline panel heading and the rangeArea chart container on a per-account tab', function (): void {
    $accountId = fpgAccount($this->db, $this->user->id, 'ASN Test');

    // "All accounts" is the default landing, so the per-account panel only
    // renders with an account id in the URL.
    $response = $this->actingAs($this->user)->get('/forecast?account='.$accountId);

    $content = (string) $response->getContent();
    expect($content)->toContain('Baseline');
    expect($content)->toContain('id="forecast-chart-baseline-'.$accountId.'"');
});

it('loads the Apex options JSON into data-options on the per-account chart container', function (): void {
    $accountId = fpgAccount($this->db, $this->user->id, 'ASN Test');

    $response = $this->actingAs($this->user)->get('/forecast?account='.$accountId);

    $content = (string) $response->getContent();
    // Blade HTML-encodes the JSON, so it cannot be json_decode'd as it stands;
    // matching the chart-type marker avoids un-escaping it first.
    expect($content)->toContain('data-options=');
    expect($content)->toMatch('/data-options="[^"]*rangeArea/');
});

it('renders the empty-state hero when the user has no accounts', function (): void {
    $response = $this->actingAs($this->user)->get('/forecast');

    $response->assertOk()
        ->assertSeeText('No forecast data yet');
});

it('redirects unauthenticated visits to /login', function (): void {
    $this->get('/forecast')->assertRedirect('/login');
});

it('falls back to the All-accounts tab when ?account= is a non-numeric tampered value', function (): void {
    // Without an account the empty-state hero would render instead, and the
    // fallback under test would never be reached.
    fpgAccount($this->db, $this->user->id, 'ASN Fallback');

    $response = $this->actingAs($this->user)->get('/forecast?account=garbage');

    $response->assertOk();
    $content = (string) $response->getContent();
    expect($content)->toContain('data-testid="all-accounts-aggregate-chart"');
});
