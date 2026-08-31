<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\User;

beforeEach(function (): void {
    Route::middleware(['web', 'ensureDeveloperMode'])
        ->get('/dev/__build-probe', static fn (): string => 'PROBE')
        ->name('dev.__build-probe');
});

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
});

function buildGateDeveloper(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

function buildGateEnv(string $environment, bool $flag = false, bool $debug = false): void
{
    /** @var ConfigRepository $config */
    $config = app(ConfigRepository::class);
    $config->set('app.env', $environment);
    $config->set('app.dev_mode', $flag);
    $config->set('app.debug', $debug);
}

function buildGateOnAPhone(): void
{
    putenv('NATIVEPHP_PLATFORM=android');
}

it('answers 404 on a shipped desktop build when no flag was passed', function (): void {
    $user = buildGateDeveloper('build-gate-desktop-closed');
    buildGateEnv('production');

    $this->actingAs($user)->get('/dev/__build-probe')->assertNotFound();
});

it('opens the console on a shipped desktop build when the flag was passed', function (): void {
    $user = buildGateDeveloper('build-gate-desktop-open');
    buildGateEnv('production', flag: true);

    $response = $this->actingAs($user)->get('/dev/__build-probe');

    $response->assertOk();
    expect($response->getContent())->toBe('PROBE');
});

it('answers 404 on a shipped mobile build that is not a debuggable one', function (): void {
    $user = buildGateDeveloper('build-gate-phone-closed');
    buildGateEnv('production');
    buildGateOnAPhone();

    $this->actingAs($user)->get('/dev/__build-probe')->assertNotFound();
});

// The flag is stripped out of every packaged mobile .env, so it is not the
// lever on a phone even for a developer who knows about it.
it('leaves a shipped mobile build shut to the desktop flag', function (): void {
    $user = buildGateDeveloper('build-gate-phone-flag');
    buildGateEnv('production', flag: true);
    buildGateOnAPhone();

    $this->actingAs($user)->get('/dev/__build-probe')->assertNotFound();
});

it('opens the console on a debuggable mobile build', function (): void {
    $user = buildGateDeveloper('build-gate-phone-open');
    buildGateEnv('production', debug: true);
    buildGateOnAPhone();

    $response = $this->actingAs($user)->get('/dev/__build-probe');

    $response->assertOk();
    expect($response->getContent())->toBe('PROBE');
});

it('leaves a development build exactly as it was', function (): void {
    $user = buildGateDeveloper('build-gate-development');
    buildGateEnv('local');

    $this->actingAs($user)->get('/dev/__build-probe')->assertOk();
});

// A 403 would confirm the console is there and merely shut; the refusal has to
// be indistinguishable from a route that does not exist.
it('refuses a shipped build with the same status as an unknown address', function (): void {
    $user = buildGateDeveloper('build-gate-status');
    buildGateEnv('production');

    $refused = $this->actingAs($user)->get('/dev/__build-probe');
    $unknown = $this->actingAs($user)->get('/dev/__no-such-address');

    expect($refused->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($refused->getStatusCode())->toBe(404);
});
