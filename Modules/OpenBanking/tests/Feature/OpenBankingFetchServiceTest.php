<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingConnectionException;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCredentialsException;
use Modules\OpenBanking\Internal\Services\OpenBankingFetchService;
use Modules\OpenBanking\Tests\Support\OfsStubRemoteSourceAdapter;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

uses(RefreshDatabase::class);

// Credentials are addressed by the reader AND the institution on the row that
// triggered the fetch, so a second bank's session can neither be reached from
// here nor stand in for one this reader never linked.

function ofsUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ofsSeedConnection(User $user, array $overrides = []): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $now = CarbonImmutable::parse('2026-07-19 06:00:00')->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId(array_merge([
        'user_id' => $user->id,
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'account_uid' => 'acc-uid-asn-1',
        'bank_display_name' => 'ASN Bank',
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::parse('2026-10-19 00:00:00')->toDateTimeString(),
        'consent_revoked_at' => null,
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

function ofsSeedCredentials(User $user, string $institutionId = OpenBankingSecretsFixture::INSTITUTION_ID): void
{
    OpenBankingSecretsFixture::seed(
        $user->id,
        $institutionId,
        consentExpiresAt: CarbonImmutable::parse('2026-10-19 00:00:00'),
    );
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));
    $this->ofsSeededUserIds = [];
});

afterEach(function (): void {
    foreach ($this->ofsSeededUserIds as $userId) {
        OpenBankingSecretsFixture::forget($userId);
    }
    CarbonImmutable::setTestNow();
});

it('fetches with the session stored for the connection\'s own bank', function (): void {
    $user = ofsUser('ofs-match');
    $this->ofsSeededUserIds[] = $user->id;
    $connectionId = ofsSeedConnection($user);
    ofsSeedCredentials($user);

    $stub = new OfsStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    /** @var OpenBankingFetchService $service */
    $service = app(OpenBankingFetchService::class);
    $service->preview($connectionId, $user);

    expect($stub->called)->toBeTrue();
});

// Two connected banks, one fetch: the second bank's session must not answer
// for the first, which is what a single global session record did.
it('reaches for each bank\'s own session when the reader has two linked', function (): void {
    $user = ofsUser('ofs-two-banks');
    $this->ofsSeededUserIds[] = $user->id;
    ofsSeedCredentials($user, OpenBankingSecretsFixture::INSTITUTION_ID);
    OpenBankingSecretsFixture::seed(
        $user->id,
        OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        consentExpiresAt: CarbonImmutable::parse('2026-10-19 00:00:00'),
        sessionId: 'fixture-session-second',
    );

    $first = ofsSeedConnection($user);
    $second = ofsSeedConnection($user, [
        'institution_id' => OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        'account_uid' => 'acc-uid-sns-1',
    ]);

    // Records what it was handed rather than only that it ran: the defect this
    // pins is a fetch that happens with the WRONG bank's session.
    $recorder = new class implements RemoteSourceAdapter
    {
        /** @var list<?string> */
        public array $sessionIds = [];

        public function format(): string
        {
            return 'enable-banking';
        }

        public function fetch(string $accountUid, FetchWindow $window, OpenBankingCredentials $credentials): Generator
        {
            $this->sessionIds[] = $credentials->sessionId;

            yield from [];

            return FetchWalk::exhausted();
        }
    };
    app()->instance(RemoteSourceAdapter::class, $recorder);

    /** @var OpenBankingFetchService $service */
    $service = app(OpenBankingFetchService::class);
    $service->preview($first, $user);
    $service->preview($second, $user);

    expect($recorder->sessionIds)->toBe(['fixture-session', 'fixture-session-second']);
});

// The row outlived its session material — a bank disconnected at the aggregator
// end, or a peer's row that arrived without one. Fetching it with whatever
// session the file does hold is exactly the substitution being refused.
it('throws rather than fetching a bank this reader holds no session for', function (): void {
    $user = ofsUser('ofs-not-linked');
    $this->ofsSeededUserIds[] = $user->id;
    // The row is for ASN; the only stored session belongs to SNS.
    $connectionId = ofsSeedConnection($user);
    ofsSeedCredentials($user, OpenBankingSecretsFixture::SECOND_INSTITUTION_ID);

    $stub = new OfsStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    /** @var OpenBankingFetchService $service */
    $service = app(OpenBankingFetchService::class);

    expect(fn () => $service->preview($connectionId, $user))
        ->toThrow(OpenBankingCredentialsException::class, OpenBankingSecretsFixture::INSTITUTION_ID);

    expect($stub->called)->toBeFalse();
});

// One reader's file cannot be read for another: the load is keyed on the user
// making the request, so a stranger's application is not even addressable.
it('does not fall back to another reader\'s stored session', function (): void {
    $owner = ofsUser('ofs-secrets-owner');
    $stranger = ofsUser('ofs-secrets-stranger');
    $this->ofsSeededUserIds[] = $owner->id;
    $this->ofsSeededUserIds[] = $stranger->id;

    ofsSeedCredentials($owner);
    $connectionId = ofsSeedConnection($stranger);

    $stub = new OfsStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    /** @var OpenBankingFetchService $service */
    $service = app(OpenBankingFetchService::class);

    expect(fn () => $service->preview($connectionId, $stranger))
        ->toThrow(OpenBankingCredentialsException::class);

    expect($stub->called)->toBeFalse();
});

// Each refusal asserts the adapter was never reached: starting a fetch against
// a half-configured connection pairs a live session with the wrong account.

it('refuses a connection id that belongs to a different user', function (): void {
    $owner = ofsUser('ofs-owner');
    $stranger = ofsUser('ofs-stranger');
    $this->ofsSeededUserIds[] = $owner->id;
    $connectionId = ofsSeedConnection($owner);
    ofsSeedCredentials($owner);

    $stub = new OfsStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    /** @var OpenBankingFetchService $service */
    $service = app(OpenBankingFetchService::class);

    expect(fn () => $service->preview($connectionId, $stranger))
        ->toThrow(OpenBankingConnectionException::class, (string) $connectionId);

    expect($stub->called)->toBeFalse();
});

it('refuses to fetch a connection that is switched off or whose consent has lapsed', function (array $overrides): void {
    $user = ofsUser('ofs-'.md5(serialize($overrides)));
    $this->ofsSeededUserIds[] = $user->id;
    $connectionId = ofsSeedConnection($user, $overrides);
    ofsSeedCredentials($user);

    $stub = new OfsStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    /** @var OpenBankingFetchService $service */
    $service = app(OpenBankingFetchService::class);

    expect(fn () => $service->preview($connectionId, $user))
        ->toThrow(OpenBankingConnectionException::class, 'consent has expired');

    expect($stub->called)->toBeFalse();
})->with([
    'disabled' => [['enabled' => false]],
    'consent in the past' => [['consent_expires_at' => CarbonImmutable::parse('2026-07-01 00:00:00')->toDateTimeString()]],
    'consent never recorded' => [['consent_expires_at' => null]],
]);

it('refuses to fetch before the consent dance has resolved an account uid', function (): void {
    $user = ofsUser('ofs-no-account');
    $this->ofsSeededUserIds[] = $user->id;
    $connectionId = ofsSeedConnection($user, ['account_uid' => null]);
    ofsSeedCredentials($user);

    $stub = new OfsStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    /** @var OpenBankingFetchService $service */
    $service = app(OpenBankingFetchService::class);

    expect(fn () => $service->preview($connectionId, $user))
        ->toThrow(OpenBankingConnectionException::class, 'account_uid');

    expect($stub->called)->toBeFalse();
});

it('refuses to fetch when no application credentials are persisted', function (): void {
    $user = ofsUser('ofs-no-credentials');
    $this->ofsSeededUserIds[] = $user->id;
    $connectionId = ofsSeedConnection($user);

    $stub = new OfsStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    /** @var OpenBankingFetchService $service */
    $service = app(OpenBankingFetchService::class);

    expect(fn () => $service->preview($connectionId, $user))
        ->toThrow(OpenBankingCredentialsException::class);

    expect($stub->called)->toBeFalse();
});
