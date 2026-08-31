<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Public\Dto\PairingPeerIdentity;
use Modules\Sync\Public\Services\PairingGateway;
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);

// expires_at earned its Zulu guarantee because it is compared lexically. Its
// siblings on the same row did not have one, so the table held two formats at
// once and a reader could not tell which it had (see the linked page).

const PAIRING_STAMP_ZULU_SHAPE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

function stampUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function eastOfUtc(string $instant): void
{
    date_default_timezone_set('Europe/Amsterdam');
    CarbonImmutable::setTestNow(CarbonImmutable::parse($instant));
}

function latestPairingRow(int $userId): object
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')
        ->where('user_id', $userId)
        ->orderByDesc('id')
        ->first();

    expect($row)->not->toBeNull();

    return (object) $row;
}

beforeEach(function (): void {
    $this->originalTimezone = date_default_timezone_get();
    $this->service = app(PairingTokenService::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    date_default_timezone_set($this->originalTimezone);
});

it('writes created_at in Zulu on the row a mint creates', function (): void {
    eastOfUtc('2026-06-15T18:30:00Z');
    $user = stampUser('stamp-issue');

    $this->service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    expect(latestPairingRow((int) $user->id)->created_at)->toMatch(PAIRING_STAMP_ZULU_SHAPE);
});

it('writes created_at and initiator_seeded_at in Zulu on the row a scanned code seeds', function (): void {
    eastOfUtc('2026-06-15T18:30:00Z');
    $user = stampUser('stamp-seed');

    $this->service->seedFromInitiator((int) $user->id, new PairingPeerIdentity('the-desktop', str_repeat('a', 64), str_repeat('b', 64)), bin2hex(random_bytes(16)));

    $row = latestPairingRow((int) $user->id);

    expect($row->created_at)->toMatch(PAIRING_STAMP_ZULU_SHAPE);
    expect($row->initiator_seeded_at)->toMatch(PAIRING_STAMP_ZULU_SHAPE);
});

it('writes accepted_at in Zulu on both roads a responder binds by', function (): void {
    eastOfUtc('2026-06-15T18:30:00Z');
    $typed = stampUser('stamp-accept-typed');
    $framed = stampUser('stamp-accept-framed');

    $typedToken = $this->service->issue((int) $typed->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    $this->service->accept($typedToken, (int) $typed->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));

    $framedToken = $this->service->issue((int) $framed->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    $this->service->applyResponderAccept(
        (int) $framed->id,
        hash('sha256', $framedToken),
        'the-phone',
        str_repeat('c', 64),
        str_repeat('d', 64),
    );

    expect(latestPairingRow((int) $typed->id)->accepted_at)->toMatch(PAIRING_STAMP_ZULU_SHAPE);
    expect(latestPairingRow((int) $framed->id)->accepted_at)->toMatch(PAIRING_STAMP_ZULU_SHAPE);
});

it('writes the confirmation stamp in Zulu when this device confirms its own side', function (): void {
    eastOfUtc('2026-06-15T18:30:00Z');
    $user = stampUser('stamp-confirm');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    $identity = app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    $token = $this->service->issue((int) $user->id, $identity->deviceId, $identity->ed25519PublicKeyHex, $identity->x25519PublicKeyHex);
    $this->service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));

    $row = latestPairingRow((int) $user->id);
    $rowId = (int) $row->id;

    $state = app(PairingGateway::class)->confirm(
        $rowId,
        (int) $user->id,
        $identity->deviceId,
        PairingSafetyDigest::forToken($rowId, (int) $user->id),
    );

    expect($state)->not->toBeNull();
    expect(latestPairingRow((int) $user->id)->initiator_confirmed_at)->toMatch(PAIRING_STAMP_ZULU_SHAPE);
});
