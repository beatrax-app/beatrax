<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Services\OpenBankingFetchService;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsFile;
use Modules\OpenBanking\Internal\Services\OpenBankingSyncRunner;
use Modules\OpenBanking\Tests\Support\AsbPerAccountStubRemoteSourceAdapter;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

uses(RefreshDatabase::class);

// The store shipped as one file for the whole installation. A reader with a
// live bank has to cross into the keyed store still connected: a migration that
// dropped the session would send them back through their bank's login for
// nothing, and one that guessed an owner would hand it to the wrong reader.

function aiwsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path(
        'Modules/OpenBanking/Database/Migrations/2026_09_05_000001_key_open_banking_secrets_by_reader_and_bank.php'
    );

    return $migration;
}

function aiwsUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 */
function aiwsWriteInstallationWideStore(array $payload): void
{
    /** @var OpenBankingSecretsFile $file */
    $file = app(OpenBankingSecretsFile::class);
    $file->write(OpenBankingSecretsFixture::legacyPath(), $payload);
}

/**
 * @return array<string, mixed>
 */
function aiwsLiveStore(string $institutionId = OpenBankingSecretsFixture::INSTITUTION_ID): array
{
    return [
        'application_id' => 'legacy-application-id',
        'private_key_pem' => 'legacy-pem',
        'session_id' => 'legacy-session',
        'consent_expires_at' => CarbonImmutable::now()->addDays(120)->toAtomString(),
        'bank_sca_host' => 'sca.asnbank.example',
        'institution_id' => $institutionId,
    ];
}

function aiwsSeedConnection(User $user, string $institutionId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => $institutionId,
        'account_uid' => 'acc-uid-legacy',
        'bank_display_name' => null,
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::now()->addDays(120)->toDateTimeString(),
        'consent_revoked_at' => null,
        'last_successful_sync_at' => null,
        'fetched_through_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05 06:30:00'));
    OpenBankingSecretsFixture::forgetLegacy();
    $this->seededUserIds = [];
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forgetLegacy();
    foreach ($this->seededUserIds as $userId) {
        OpenBankingSecretsFixture::forget($userId);
    }
    CarbonImmutable::setTestNow();
});

it('hands the live session to the reader whose connection row names that bank', function (): void {
    $reader = aiwsUser('aiws-owner');
    $bystander = aiwsUser('aiws-bystander');
    $this->seededUserIds = [$reader->id, $bystander->id];

    $connectionId = aiwsSeedConnection($reader, OpenBankingSecretsFixture::INSTITUTION_ID);
    aiwsWriteInstallationWideStore(aiwsLiveStore());

    aiwsMigration()->up();

    $secrets = OpenBankingSecretsFixture::repository();
    $adopted = $secrets->loadOrThrow($reader->id, OpenBankingSecretsFixture::INSTITUTION_ID);

    expect($adopted->sessionId)->toBe('legacy-session')
        ->and($adopted->applicationId)->toBe('legacy-application-id')
        ->and($adopted->privateKeyPem)->toBe('legacy-pem')
        ->and($adopted->bankScaHost)->toBe('sca.asnbank.example')
        ->and($adopted->consentExpiresAt)->not->toBeNull()
        ->and($secrets->hasApplication($bystander->id))->toBeFalse()
        // The installation-wide file is gone only once the keyed one is written.
        ->and(is_file(OpenBankingSecretsFixture::legacyPath()))->toBeFalse();

    // Still connected means the next unattended round fetches, not that a row
    // survived: nothing here asks the reader to log in at their bank again.
    $adapter = new AsbPerAccountStubRemoteSourceAdapter(['acc-uid-legacy' => []]);
    app()->instance(RemoteSourceAdapter::class, $adapter);
    app()->forgetInstance(OpenBankingFetchService::class);
    app()->forgetInstance(OpenBankingSyncRunner::class);

    Artisan::call('open-banking:sync-due');

    expect($adapter->seen)->toBe([[
        'accountUid' => 'acc-uid-legacy',
        'institutionId' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'sessionId' => 'legacy-session',
    ]]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('last_attempt_status'))
        ->toBe('ok');
});

// No connection row means no consent to lose, so the application half goes to
// the only account that could have registered it.
it('hands a bankless store to the one account on the installation', function (): void {
    $reader = aiwsUser('aiws-lone');
    $this->seededUserIds = [$reader->id];

    aiwsWriteInstallationWideStore([
        'application_id' => 'legacy-application-id',
        'private_key_pem' => 'legacy-pem',
    ]);

    aiwsMigration()->up();

    $secrets = OpenBankingSecretsFixture::repository();

    expect($secrets->hasApplication($reader->id))->toBeTrue()
        ->and($secrets->connectedInstitutions($reader->id))->toBe([])
        ->and(is_file(OpenBankingSecretsFixture::legacyPath()))->toBeFalse();
});

it('leaves a bankless store alone rather than pick between two accounts', function (): void {
    $first = aiwsUser('aiws-first');
    $second = aiwsUser('aiws-second');
    $this->seededUserIds = [$first->id, $second->id];

    aiwsWriteInstallationWideStore([
        'application_id' => 'legacy-application-id',
        'private_key_pem' => 'legacy-pem',
    ]);

    aiwsMigration()->up();

    $secrets = OpenBankingSecretsFixture::repository();

    expect($secrets->hasApplication($first->id))->toBeFalse()
        ->and($secrets->hasApplication($second->id))->toBeFalse()
        ->and(is_file(OpenBankingSecretsFixture::legacyPath()))->toBeTrue();
});

it('does nothing at all on an installation that never connected a bank', function (): void {
    aiwsUser('aiws-never');

    expect(fn (): mixed => aiwsMigration()->up())->not->toThrow(Throwable::class);
    expect(is_file(OpenBankingSecretsFixture::legacyPath()))->toBeFalse();
});

// A store that cannot be read is repairable; one this migration deleted is not.
it('leaves an unreadable store where it is and says so', function (): void {
    $reader = aiwsUser('aiws-unreadable');
    $this->seededUserIds = [$reader->id];

    @mkdir(dirname(OpenBankingSecretsFixture::legacyPath()), 0700, recursive: true);
    file_put_contents(OpenBankingSecretsFixture::legacyPath(), 'not-json-at-all');

    Log::spy();

    aiwsMigration()->up();

    expect(is_file(OpenBankingSecretsFixture::legacyPath()))->toBeTrue()
        ->and(OpenBankingSecretsFixture::repository()->hasApplication($reader->id))->toBeFalse();

    // The file layer names the unparseable file and the migration names what
    // it could not do with it, so a log reader has both halves.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'could not be parsed'))
        ->once();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'could not be adopted'))
        ->once();
});
