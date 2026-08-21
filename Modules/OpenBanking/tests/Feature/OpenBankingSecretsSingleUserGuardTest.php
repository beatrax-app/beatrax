<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;

uses(RefreshDatabase::class);

/** @link ../../../../.docs/features/open-banking/secrets-at-rest.md#single-user-caveat */
function obssgUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function obssgCredentials(): OpenBankingCredentials
{
    return new OpenBankingCredentials(
        applicationId: 'app-fixture',
        privateKeyPem: 'fixture-pem',
        sessionId: 'fixture-session',
        consentExpiresAt: CarbonImmutable::now()->addDays(180),
        bankScaHost: 'sca.asnbank.example',
        institutionId: 'ASNBNL21',
    );
}

afterEach(function (): void {
    $path = storage_path('app/secrets/open-banking.json');
    if (is_file($path)) {
        @unlink($path);
    }
    if (is_file($path.'.tmp')) {
        @unlink($path.'.tmp');
    }
});

it('a save while a SECOND user exists logs the single-user-constraint warning (and still writes)', function (): void {
    obssgUser('obssg-first');
    obssgUser('obssg-second');

    Log::spy();

    /** @var OpenBankingSecretsRepository $repo */
    $repo = app(OpenBankingSecretsRepository::class);
    $repo->save(obssgCredentials());

    // The guard logs but never blocks the write.
    expect($repo->load())->not->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'no per-user isolation')
            && str_contains($message, 'single global secrets file'));
});

it('a save with a single user emits no single-user-constraint warning', function (): void {
    obssgUser('obssg-only');

    Log::spy();

    /** @var OpenBankingSecretsRepository $repo */
    $repo = app(OpenBankingSecretsRepository::class);
    $repo->save(obssgCredentials());

    expect($repo->load())->not->toBeNull();

    Log::shouldNotHaveReceived('warning');
});
