<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Core\Public\Services\DevConsoleBuildGate;

// The gate compared APP_ENV against the single literal 'production', so every
// other spelling of a shipped build -- a self-hosted 'staging', a hand-written
// 'prod', a capitalised 'Production' -- read as a development checkout and
// opened the artisan runner and the SQL box with no second key at all.
function buildEnvironmentNamed(string $environment): DevConsoleBuildGate
{
    /** @var ConfigRepository $config */
    $config = app(ConfigRepository::class);
    $config->set('app.env', $environment);
    $config->set('app.dev_mode', false);
    $config->set('app.debug', false);

    return app(DevConsoleBuildGate::class);
}

it('treats every environment that is not a development one as a shipped build', function (string $environment): void {
    expect(buildEnvironmentNamed($environment)->permits())->toBeFalse();
})->with([
    'production',
    'Production',
    'PRODUCTION',
    'prod',
    'staging',
    'stage',
    'acceptance',
    'demo',
    '',
    'live',
]);

// The two the repository actually writes: .env.example ships local, and every
// CI job and the phpunit suite export testing.
it('leaves the two development environments open', function (string $environment): void {
    expect(buildEnvironmentNamed($environment)->permits())->toBeTrue();
})->with(['local', 'testing']);

// A shipped build still opens on its own key, so the allow-list narrows which
// builds need one rather than changing what the key is.
it('still opens a staging build that was launched with the desktop flag', function (): void {
    $gate = buildEnvironmentNamed('staging');

    /** @var ConfigRepository $config */
    $config = app(ConfigRepository::class);
    $config->set('app.dev_mode', true);

    expect($gate->permits())->toBeTrue();
});
