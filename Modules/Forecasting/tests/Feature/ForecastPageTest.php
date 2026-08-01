<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

/*
 * /forecast feature tests — the page heading + subhead + helper
 * link, the horizon segmented control with the default selection,
 * the per-account tab bar, the rangeArea chart container, the
 * data-options Apex JSON attribute, the Updating... caption when
 * the latest run is pending, and the empty-state hero when the
 * user has no accounts.
 */

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
    // 30 days option carries aria-checked="true"
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

    // Wave 5 makes "All accounts" the default landing; per-account
    // panel renders only when an account id is in the URL.
    $response = $this->actingAs($this->user)->get('/forecast?account='.$accountId);

    $content = (string) $response->getContent();
    expect($content)->toContain('Baseline');
    expect($content)->toContain('id="forecast-chart-baseline-'.$accountId.'"');
});

it('loads the Apex options JSON into data-options on the per-account chart container', function (): void {
    $accountId = fpgAccount($this->db, $this->user->id, 'ASN Test');

    $response = $this->actingAs($this->user)->get('/forecast?account='.$accountId);

    $content = (string) $response->getContent();
    // Find the data-options attribute on the chart wrapper. The
    // JSON is HTML-encoded by Blade so we cannot json_decode it
    // directly without un-escaping first; the simpler check is
    // that the rangeArea chart type marker survives the encoding.
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
    // Seed at least one account so the empty-state hero is not shown
    // (we want to verify the All-accounts aggregate view is rendered,
    // not the empty fallback).
    fpgAccount($this->db, $this->user->id, 'ASN Fallback');

    $response = $this->actingAs($this->user)->get('/forecast?account=garbage');

    $response->assertOk();
    $content = (string) $response->getContent();
    // The all-accounts aggregate chart container ID is the testid the
    // aggregate-line-chart partial uses.
    expect($content)->toContain('data-testid="all-accounts-aggregate-chart"');
});
