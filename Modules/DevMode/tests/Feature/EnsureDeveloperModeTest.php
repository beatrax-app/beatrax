<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Models\User;
use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Contracts\NavigationRegistry;

// A throwaway route keeps these assertions on the middleware alone, with no
// dev-shell or Blade rendering in the way.
beforeEach(function (): void {
    Route::middleware(['web', 'ensureDeveloperMode'])
        ->get('/dev/__probe', static fn (): string => 'PROBE')
        ->name('dev.__probe');
});

function edmUser(bool $isDeveloper, string $username = 'edm-fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('returns 404 for an unauthenticated request to /dev/__probe (NotFoundHttpException, not 403)', function (): void {
    // EnsureDatabaseReady redirects while the users table is empty, which
    // would mask the 404 this test is about.
    edmUser(true, 'edm-seed-for-unauth');

    $response = $this->get('/dev/__probe');

    $response->assertNotFound();
});

it('returns 404 for an authenticated non-developer request to /dev/__probe (NotFoundHttpException, not 403)', function (): void {
    $user = edmUser(false, 'edm-nondev');

    $response = $this->actingAs($user)->get('/dev/__probe');

    $response->assertNotFound();
});

it('returns 200 with body "PROBE" for an authenticated developer request to /dev/__probe', function (): void {
    $user = edmUser(true, 'edm-dev');

    $response = $this->actingAs($user)->get('/dev/__probe');

    $response->assertOk();
    expect($response->getContent())->toBe('PROBE');
});

it('resolves all four Public contracts via the container', function (): void {
    /** @var DevCommandRegistry $commands */
    $commands = app(DevCommandRegistry::class);
    expect($commands)->toBeInstanceOf(DevCommandRegistry::class);
    expect($commands->safe())->toHaveCount(9);
    expect($commands->destructive())->toHaveCount(4);

    /** @var NavigationRegistry $nav */
    $nav = app(NavigationRegistry::class);
    expect($nav)->toBeInstanceOf(NavigationRegistry::class);
    expect($nav->all())->not->toBe([]);

    /** @var AppActionRegistry $actions */
    $actions = app(AppActionRegistry::class);
    expect($actions)->toBeInstanceOf(AppActionRegistry::class);
    expect($actions->all())->not->toBe([]);

    /** @var AuditWriter $audit */
    $audit = app(AuditWriter::class);
    expect($audit)->toBeInstanceOf(AuditWriter::class);
});
