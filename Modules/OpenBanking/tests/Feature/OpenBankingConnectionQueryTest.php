<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\OpenBanking\Internal\Dto\OpenBankingConnectionView;
use Modules\OpenBanking\Internal\Enums\ConsentStatus;
use Modules\OpenBanking\Internal\Services\OpenBankingConnectionQuery;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

uses(RefreshDatabase::class);

function obcqUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function obcqSeedConnection(User $user, string $institutionId, ?string $consentExpiresAt): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $now = CarbonImmutable::parse('2026-07-19 06:00:00')->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => $institutionId,
        'account_uid' => 'acc-uid-'.strtolower($institutionId),
        'bank_display_name' => 'ignored — derived at read time',
        'enabled' => true,
        'consent_expires_at' => $consentExpiresAt,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function obcqQuery(): OpenBankingConnectionQuery
{
    $clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-07-19 06:30:00');
        }
    };

    return new OpenBankingConnectionQuery(
        app(DatabaseManager::class),
        OpenBankingSecretsFixture::repository(),
        $clock,
    );
}

/**
 * @param  list<OpenBankingConnectionView>  $views
 * @return list<string>
 */
function obcqInstitutionIds(array $views): array
{
    return array_map(static fn (OpenBankingConnectionView $view): string => $view->institutionId, $views);
}

beforeEach(function (): void {
    $this->reader = obcqUser('obcq-reader');
    $this->otherReader = obcqUser('obcq-other-reader');
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forget((int) $this->reader->id);
    OpenBankingSecretsFixture::forget((int) $this->otherReader->id);
});

// The application half is registered in the wizard's first step, long before
// any bank is linked, so holding it is not evidence of a fetchable connection.
it('offers nothing while the reader has registered an application but linked no bank', function (): void {
    OpenBankingSecretsFixture::seedApplication((int) $this->reader->id);
    $connectionId = obcqSeedConnection($this->reader, OpenBankingSecretsFixture::INSTITUTION_ID, '2026-10-19 00:00:00');

    expect(obcqQuery()->forUser((int) $this->reader->id))->toBe([])
        ->and(obcqQuery()->forConnection((int) $this->reader->id, $connectionId))->toBeNull();
});

// The SCA host is written on the way OUT to the bank and the session only on
// the way back, so an abandoned consent leaves a record with no session in it.
it('offers nothing for a bank whose consent was begun and never came back', function (): void {
    OpenBankingSecretsFixture::seedApplication((int) $this->reader->id);
    OpenBankingSecretsFixture::repository()->rememberScaHost(
        (int) $this->reader->id,
        OpenBankingSecretsFixture::INSTITUTION_ID,
        'sca.asnbank.example',
    );
    $connectionId = obcqSeedConnection($this->reader, OpenBankingSecretsFixture::INSTITUTION_ID, '2026-10-19 00:00:00');

    expect(obcqQuery()->forUser((int) $this->reader->id))->toBe([])
        ->and(obcqQuery()->forConnection((int) $this->reader->id, $connectionId))->toBeNull();
});

// The secrets file and the connections table are written by different steps of
// the consent dance, so the file can name an institution the user has no row for.
it('reports no connection when no row matches the live session', function (): void {
    OpenBankingSecretsFixture::seed((int) $this->reader->id);

    expect(obcqQuery()->forUser((int) $this->reader->id))->toBe([]);
});

it('reads the consent status from how much of the window is left', function (?string $expiresAt, ConsentStatus $expected): void {
    OpenBankingSecretsFixture::seed((int) $this->reader->id);
    obcqSeedConnection($this->reader, OpenBankingSecretsFixture::INSTITUTION_ID, $expiresAt);

    $views = obcqQuery()->forUser((int) $this->reader->id);

    expect($views)->toHaveCount(1)
        ->and($views[0]->consentStatus)->toBe($expected);
})->with([
    // An unknown expiry is not evidence of a live consent.
    'never recorded' => [null, ConsentStatus::Expired],
    'already past' => ['2026-07-18 06:00:00', ConsentStatus::Expired],
    'exactly now' => ['2026-07-19 06:30:00', ConsentStatus::Expired],
    'inside the 14-day window' => ['2026-07-25 06:00:00', ConsentStatus::Expiring],
    'on the 14-day boundary' => ['2026-08-02 06:30:00', ConsentStatus::Expiring],
    'beyond the window' => ['2026-10-19 00:00:00', ConsentStatus::Connected],
]);

// The stored column is a string. '2026-08-02' compared as text against
// '2026-08-02 06:30:00' sorts BEFORE it, which would put the boundary day on
// the wrong side of the window; parsing to an instant is what prevents that.
it('reads a date-only expiry as the whole boundary day, not as its midnight string', function (): void {
    OpenBankingSecretsFixture::seed((int) $this->reader->id);
    $connectionId = obcqSeedConnection($this->reader, OpenBankingSecretsFixture::INSTITUTION_ID, '2026-07-25');

    $view = obcqQuery()->forConnection((int) $this->reader->id, $connectionId);

    expect($view)->not->toBeNull()
        ->and($view->consentStatus)->toBe(ConsentStatus::Expiring);
});

// bank_display_name is a column the callback controller never populates, so an
// unmapped institution has to fall back to its own id rather than render blank.
it('derives the bank display name from the institution id', function (string $institutionId, string $expected): void {
    OpenBankingSecretsFixture::seed((int) $this->reader->id, $institutionId);
    $connectionId = obcqSeedConnection($this->reader, $institutionId, '2026-10-19 00:00:00');

    $view = obcqQuery()->forConnection((int) $this->reader->id, $connectionId);

    expect($view)->not->toBeNull()
        ->and($view->bankDisplayName)->toBe($expected);
})->with([
    ['ASNBNL21', 'ASN Bank'],
    ['SNSBNL21', 'SNS (de Volksbank)'],
    ['RABONL2U', 'RABONL2U'],
]);

// Two banks connected at once is the whole point of keying the store by bank:
// the reader gets both, in the order their rows were created, rather than
// whichever session the one file happened to hold last.
it('returns both connected banks in row order', function (): void {
    OpenBankingSecretsFixture::seed((int) $this->reader->id, OpenBankingSecretsFixture::INSTITUTION_ID);
    OpenBankingSecretsFixture::seed((int) $this->reader->id, OpenBankingSecretsFixture::SECOND_INSTITUTION_ID);

    // Seeded ASN-first above and rowed SNS-first here, so an order taken from
    // the secrets map instead of from the rows would come back reversed.
    $snsId = obcqSeedConnection($this->reader, OpenBankingSecretsFixture::SECOND_INSTITUTION_ID, '2026-10-19 00:00:00');
    $asnId = obcqSeedConnection($this->reader, OpenBankingSecretsFixture::INSTITUTION_ID, '2026-10-19 00:00:00');

    $views = obcqQuery()->forUser((int) $this->reader->id);

    expect($views)->toHaveCount(2)
        ->and(obcqInstitutionIds($views))->toBe([
            OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
            OpenBankingSecretsFixture::INSTITUTION_ID,
        ])
        ->and(array_map(static fn (OpenBankingConnectionView $view): int => $view->connectionId, $views))
        ->toBe([$snsId, $asnId]);
});

// A connection id is a bare integer off a card the reader mounts, so the owner
// predicate is the only thing standing between one household member's screen
// and another's bank — including when both readers hold the same bank.
it('answers nothing for a connection id belonging to another reader', function (): void {
    OpenBankingSecretsFixture::seed((int) $this->reader->id);
    OpenBankingSecretsFixture::seed((int) $this->otherReader->id);
    $connectionId = obcqSeedConnection($this->reader, OpenBankingSecretsFixture::INSTITUTION_ID, '2026-10-19 00:00:00');

    expect(obcqQuery()->forConnection((int) $this->otherReader->id, $connectionId))->toBeNull()
        ->and(obcqQuery()->forUser((int) $this->otherReader->id))->toBe([])
        ->and(obcqQuery()->forConnection((int) $this->reader->id, $connectionId))->not->toBeNull();
});
