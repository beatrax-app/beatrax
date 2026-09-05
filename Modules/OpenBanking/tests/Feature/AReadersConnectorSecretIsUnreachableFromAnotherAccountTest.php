<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Modules\Auth\Public\Actions\DeleteAccountAction;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingConnectionException;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCredentialsException;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingConnectionCard;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Internal\Services\OpenBankingConnectionQuery;
use Modules\OpenBanking\Internal\Services\OpenBankingFetchService;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Internal\Services\OpenBankingSyncRunner;
use Modules\OpenBanking\Public\Http\Livewire\OpenBankingStatusRow;
use Modules\OpenBanking\Tests\Support\AsbPerAccountStubRemoteSourceAdapter;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

uses(RefreshDatabase::class);

// The store used to hold one file for the whole installation and only log a
// warning when a second account existed. Reading somebody else's connector
// secret is now unaddressable rather than discouraged: there is no method here
// that does not name a reader.

function orcsUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function orcsSeedConnection(User $user, string $institutionId, string $accountUid): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => $institutionId,
        'account_uid' => $accountUid,
        'bank_display_name' => null,
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString(),
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
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));

    $this->reader = orcsUser('orcs-reader');
    $this->other = orcsUser('orcs-other');

    OpenBankingSecretsFixture::forget($this->reader->id);
    OpenBankingSecretsFixture::forget($this->other->id);

    OpenBankingSecretsFixture::seed($this->reader->id, sessionId: 'readers-session');
    $this->connectionId = orcsSeedConnection(
        $this->reader,
        OpenBankingSecretsFixture::INSTITUTION_ID,
        'acc-uid-readers',
    );
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forget($this->reader->id);
    OpenBankingSecretsFixture::forget($this->other->id);
    CarbonImmutable::setTestNow();
});

// The structural half. A behavioural test can only cover the paths somebody
// thought of; this one fails the moment a reader-less accessor is added.
it('offers no way to reach a stored secret without naming a reader', function (): void {
    $reflection = new ReflectionClass(OpenBankingSecretsRepository::class);

    $unscoped = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== OpenBankingSecretsRepository::class) {
            continue;
        }

        $first = $method->getParameters()[0] ?? null;
        $type = $first?->getType();
        $named = $first?->getName() === 'userId'
            && $type instanceof ReflectionNamedType
            && $type->getName() === 'int';

        if (! $named) {
            $unscoped[] = $method->getName();
        }
    }

    // legacyInstitutionId reads the pre-keying file, which by construction
    // names no reader, and returns an institution id rather than any credential.
    expect($unscoped)->toBe(['legacyInstitutionId']);
});

it('answers a second reader as if the first had never connected', function (): void {
    $secrets = OpenBankingSecretsFixture::repository();

    expect($secrets->hasApplication($this->other->id))->toBeFalse()
        ->and($secrets->connectedInstitutions($this->other->id))->toBe([])
        ->and($secrets->load($this->other->id))->toBeNull()
        ->and($secrets->load($this->other->id, OpenBankingSecretsFixture::INSTITUTION_ID))->toBeNull();

    expect(fn (): mixed => $secrets->loadOrThrow($this->other->id, OpenBankingSecretsFixture::INSTITUTION_ID))
        ->toThrow(OpenBankingCredentialsException::class);

    // And the first reader still has what they had, so the isolation is not
    // "nobody can read it".
    expect($secrets->loadOrThrow($this->reader->id, OpenBankingSecretsFixture::INSTITUTION_ID)->sessionId)
        ->toBe('readers-session');
});

it('keeps the two readers in files neither can address as the other', function (): void {
    expect(OpenBankingSecretsFixture::path($this->reader->id))
        ->not->toBe(OpenBankingSecretsFixture::path($this->other->id))
        ->and(is_file(OpenBankingSecretsFixture::path($this->reader->id)))->toBeTrue()
        ->and(is_file(OpenBankingSecretsFixture::path($this->other->id)))->toBeFalse();

    OpenBankingSecretsFixture::seed($this->other->id, sessionId: 'others-session');
    OpenBankingSecretsFixture::repository()->clear($this->other->id);

    expect(is_file(OpenBankingSecretsFixture::path($this->reader->id)))->toBeTrue()
        ->and(OpenBankingSecretsFixture::repository()->loadOrThrow(
            $this->reader->id,
            OpenBankingSecretsFixture::INSTITUTION_ID,
        )->sessionId)->toBe('readers-session');
});

it('shows a second reader none of the first reader\'s connections', function (): void {
    /** @var OpenBankingConnectionQuery $query */
    $query = app(OpenBankingConnectionQuery::class);

    expect($query->forUser($this->other->id))->toBe([])
        ->and($query->forConnection($this->other->id, $this->connectionId))->toBeNull()
        ->and($query->forUser($this->reader->id))->toHaveCount(1);

    $this->actingAs($this->other);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', false)
        ->assertSet('connectionIds', [])
        ->assertSet('connectedBanks', '');

    Livewire::test(OpenBankingStatusRow::class)
        ->assertSet('expired', false)
        ->assertSee('No bank connected');

    Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $this->connectionId])
        ->assertSet('enabled', false)
        ->assertSet('bankDisplayName', '');
});

it('refuses a fetch a second reader asks for against the first reader\'s connection', function (): void {
    $adapter = new AsbPerAccountStubRemoteSourceAdapter([]);
    app()->instance(RemoteSourceAdapter::class, $adapter);
    app()->forgetInstance(OpenBankingFetchService::class);

    /** @var OpenBankingFetchService $fetch */
    $fetch = app(OpenBankingFetchService::class);

    expect(fn (): mixed => $fetch->preview($this->connectionId, $this->other))
        ->toThrow(OpenBankingConnectionException::class);
    expect(fn (): mixed => $fetch->fetchAndConfirm($this->connectionId, $this->other))
        ->toThrow(OpenBankingConnectionException::class);

    // The refusal is the row predicate, not a failed read: the adapter is never
    // reached, so no credential of anybody's was loaded on the way.
    expect($adapter->seen)->toBe([]);
});

// The unattended path runs with no signed-in reader at all, so "whose secret"
// can only come from the row. Both readers hold a session for the very same
// bank here, which under one installation-wide file was one session.
it('runs the scheduled sync with the connection owner\'s session, and no reader is signed in', function (): void {
    OpenBankingSecretsFixture::seed($this->other->id, sessionId: 'others-session');

    $adapter = new AsbPerAccountStubRemoteSourceAdapter(['acc-uid-readers' => []]);
    app()->instance(RemoteSourceAdapter::class, $adapter);
    app()->forgetInstance(OpenBankingFetchService::class);
    app()->forgetInstance(OpenBankingSyncRunner::class);

    Artisan::call('open-banking:sync-due');

    expect($adapter->seen)->toBe([[
        'accountUid' => 'acc-uid-readers',
        'institutionId' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'sessionId' => 'readers-session',
    ]]);
});

// A deleted account must take its connector secret with it: ids are reused by
// SQLite, so a file left behind is one a future account inherits.
it('takes a reader\'s connector secret with the account, leaving the other reader\'s alone', function (): void {
    OpenBankingSecretsFixture::seed($this->other->id, sessionId: 'others-session');

    /** @var DeleteAccountAction $delete */
    $delete = app(DeleteAccountAction::class);
    $delete($this->other, 'fixture-password');

    expect(is_file(OpenBankingSecretsFixture::path($this->other->id)))->toBeFalse()
        ->and(is_file(OpenBankingSecretsFixture::path($this->reader->id)))->toBeTrue();
});
