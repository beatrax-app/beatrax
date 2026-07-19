<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\User;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

uses(RefreshDatabase::class);

/*
 * WR-08: the on-disk secrets file is a single GLOBAL blob with no per-user
 * keying (SINGLE-USER v1). save() carries a defensive guard that logs
 * loudly — but never throws — if it is ever asked to write while more than
 * one user account exists, so the missing per-user isolation is auditable
 * without breaking the (single-user) green suites.
 */

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

it('WR-08: a save while a SECOND user exists logs the single-user-constraint warning (and still writes)', function (): void {
    obssgUser('obssg-first');
    obssgUser('obssg-second');

    Log::spy();

    /** @var OpenBankingSecretsRepository $repo */
    $repo = app(OpenBankingSecretsRepository::class);
    $repo->save(obssgCredentials());

    // The guard logs but never blocks the write (SINGLE-USER v1 is a
    // documented-and-audited constraint, not a hard failure).
    expect($repo->load())->not->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'WR-08')
            && str_contains($message, 'SINGLE-USER v1'));
});

it('WR-08: a save with a single user emits no single-user-constraint warning', function (): void {
    obssgUser('obssg-only');

    Log::spy();

    /** @var OpenBankingSecretsRepository $repo */
    $repo = app(OpenBankingSecretsRepository::class);
    $repo->save(obssgCredentials());

    expect($repo->load())->not->toBeNull();

    Log::shouldNotHaveReceived('warning');
});
