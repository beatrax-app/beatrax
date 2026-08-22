<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;
use Modules\Sync\Internal\Crypto\GdkEpochWrapSignature;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Crypto\GdkWrapOutcome;
use Modules\Sync\Internal\Crypto\GdkWrapRecipient;
use Modules\Sync\Internal\Crypto\LocallyKeyedRowsProbe;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Public\Services\BlindIndexCodec;

uses(RefreshDatabase::class);

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
function bikUser(string $username): User
{
    return User::query()->create([
        'username' => $username.'-'.bin2hex(random_bytes(3)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{0: string, 1: string} [senderDeviceId, ed25519SecretKeyHex]
 */
function bikConfirmedSender(int $userId): array
{
    $sigKp = sodium_crypto_sign_keypair();
    $boxKp = sodium_crypto_box_keypair();
    $deviceId = 'bik-sender-'.bin2hex(random_bytes(4));

    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $deviceId,
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($sigKp)),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey($boxKp)),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-09T10:00:00Z',
        'confirmed_at' => '2026-07-09T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ]);

    return [$deviceId, sodium_bin2hex(sodium_crypto_sign_secretkey($sigKp))];
}

/**
 * @return array{0: DeviceIdentityDto, 1: string, 2: string, 3: string} [self identity, senderId, senderSecretHex, peerKeyHex]
 */
function bikInboundWrapParts(User $user, Session $session): array
{
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $self = $identityService->generateAndPersist((int) $user->id, $session);

    [$senderId, $senderSecretHex] = bikConfirmedSender((int) $user->id);

    // Deterministically below any minted key, so a test about WHO wins is not
    // silently a test about which random hex sorted first.
    return [$self, $senderId, $senderSecretHex, str_repeat('0', 64)];
}

// A confirmed, non-self peer this device may fan out to.
function bikConfirmedRecipient(int $userId): int
{
    $boxKp = sodium_crypto_box_keypair();
    $sigKp = sodium_crypto_sign_keypair();

    return (int) app(DatabaseManager::class)->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => 'bik-recipient-'.bin2hex(random_bytes(4)),
        'name' => 'joining phone',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($sigKp)),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey($boxKp)),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-09T10:00:00Z',
        'confirmed_at' => '2026-07-09T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ]);
}

function bikRecipientDeviceId(int $userId, int $registryId): string
{
    return (string) app(DatabaseManager::class)->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('id', $registryId)
        ->value('device_id');
}

function bikDeliver(User $user, Session $session, DeviceIdentityDto $self, string $senderId, string $senderSecretHex, string $keyHex, bool $senderKeyed = false): void
{
    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    $wrap = $rotation->buildGdkEpochWrap(
        0,
        sodium_hex2bin($keyHex),
        new GdkWrapRecipient($self->deviceId, sodium_hex2bin($self->x25519PublicKeyHex)),
        $senderId,
        $senderSecretHex,
        GdkEpochWrapSignature::ROLE_BLIND_INDEX,
        $senderKeyed,
    );

    app(GdkEpochControlHandler::class)->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);
}

function bikDeliverOutcome(User $user, Session $session, DeviceIdentityDto $self, string $senderId, string $senderSecretHex, string $keyHex, bool $senderKeyed = false): GdkWrapOutcome
{
    $wrap = app(GdkRotationService::class)->buildGdkEpochWrap(
        GdkEpochWrapSignature::BLIND_INDEX_EPOCH_ID,
        sodium_hex2bin($keyHex),
        new GdkWrapRecipient($self->deviceId, sodium_hex2bin($self->x25519PublicKeyHex)),
        $senderId,
        $senderSecretHex,
        GdkEpochWrapSignature::ROLE_BLIND_INDEX,
        $senderKeyed,
    );

    return app(GdkEpochControlHandler::class)->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);
}

// The path B1 walks: enable sync with nothing imported, so the sweep converts
// nothing, then import. Every row written after that is keyed at write time and
// so is never convertible — which is why the sweep marker cannot answer whether
// this device holds keyed rows.
function bikEnrolThenImport(User $user, Session $session): string
{
    app(EncryptionMigrationService::class)->migrate($user, $session);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'bik account',
        'slug' => 'bik-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00BIKX'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/bik.csv',
        'sha256' => hash('sha256', 'bik-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
        'status' => 'previewed',
    ]);

    $source = new SourceTransactionDto(
        bookedAt: CarbonImmutable::parse('2026-08-01 12:00:00'),
        postedAt: CarbonImmutable::parse('2026-08-01'),
        valueDate: CarbonImmutable::parse('2026-08-01'),
        ownIban: (string) $account->iban,
        counterpartyIban: 'NL11RABO0123456789',
        counterpartyName: 'Zilveren Kruis',
        currency: 'EUR',
        amountMinor: -12300,
        sourceRef: 'BIK-1',
        description: 'health insurance',
        rawPayload: [],
        sourceRowIndex: 0,
    );

    $canonical = app(NormalizeStage::class)->run($source, (int) $account->id, $user, (int) $run->id, 'asn-csv');
    app(RecordsTransactions::class)([$canonical], $user, captureForSync: false);

    return (string) app(GdkKeyringService::class)->blindIndexKeyHex((int) $user->id, $session);
}

// The question adoptsBlindIndexKey() actually asks. Given a device id so it
// can tell rows this device authored from rows it merely received.
function bikHoldsKeyedRows(User $user, Session $session, string $deviceId = ''): bool
{
    $keyHex = (string) app(GdkKeyringService::class)->blindIndexKeyHex((int) $user->id, $session);

    return app(LocallyKeyedRowsProbe::class)->holdsRowsKeyedUnder((int) $user->id, $deviceId, $keyHex, $session);
}

// What catch-up writes into a LOCKED device: counterparty_normalized is a
// non-sensitive _create_required column, so a digest under the PEER's key is
// applied verbatim while the sealed columns quarantine. The name is readable
// because the epoch keys travel with the rows.
function bikSeedPeerKeyedRows(User $user, string $foreignKeyHex): void
{
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'peer account',
        'slug' => 'bik-peer-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00BIKP'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);

    $foreign = app(BlindIndexCodec::class)->deriveWithKey(
        BlindIndexCodec::DOMAIN_COUNTERPARTY_NORMALIZED,
        'zilveren kruis',
        (int) $user->id,
        $foreignKeyHex,
    );

    app(DatabaseManager::class)->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => bikImportRun($user),
        'posted_at' => '2026-08-01',
        'booked_at' => '2026-08-01 12:00:00',
        'value_date' => '2026-08-01',
        'amount_minor' => -12300,
        'currency' => 'EUR',
        'settled_amount_minor' => -12300,
        'settled_currency' => 'EUR',
        'type' => 'expense',
        'description' => 'health insurance',
        'counterparty_name' => 'Zilveren Kruis',
        'counterparty_normalized' => $foreign,
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 0,
        'fingerprint' => hash('sha256', 'bik-peer-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 1,
        'created_at' => '2026-08-01T12:00:00Z',
        'updated_at' => '2026-08-01T12:00:00Z',
    ]);
}

// A minimal import run for the raw fixture inserts below, which bypass the
// pipeline on purpose: they are standing in for what op-log replay writes.
function bikImportRun(User $user): int
{
    return (int) ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/bik-fixture.csv',
        'sha256' => hash('sha256', 'bik-fixture-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
        'status' => 'previewed',
    ])->id;
}

// A ledger of nothing but named-payer-less SEPA credits: every transaction's
// counterparty_normalized is the sentinel, and the one value keyed under this
// device's own key is the payer IBAN on the income series.
function bikSeedIncomeOnlyLedger(User $user, string $localKeyHex): void
{
    $iban = 'NL10BANK0000000101';

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'salary account',
        'slug' => 'bik-income-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00BIKI'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => bikImportRun($user),
        'posted_at' => '2026-08-01',
        'booked_at' => '2026-08-01 12:00:00',
        'value_date' => '2026-08-01',
        'amount_minor' => 250000,
        'currency' => 'EUR',
        'settled_amount_minor' => 250000,
        'settled_currency' => 'EUR',
        'type' => 'income',
        'description' => 'salaris',
        'counterparty_name' => null,
        'counterparty_iban' => $iban,
        'counterparty_normalized' => CounterpartyKey::NONE,
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 0,
        'fingerprint' => hash('sha256', 'bik-income-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 1,
        'created_at' => '2026-08-01T12:00:00Z',
        'updated_at' => '2026-08-01T12:00:00Z',
    ]);

    $db->connection()->table('recurring_series')->insert([
        'user_id' => $user->id,
        'direction' => 'income',
        'detected_name' => 'salaris',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => 250000,
        'latest_currency' => 'EUR',
        'cluster_counterparty_key' => app(BlindIndexCodec::class)->deriveWithKey(
            BlindIndexCodec::DOMAIN_COUNTERPARTY_IBAN,
            CounterpartyKey::normalizeIban($iban),
            (int) $user->id,
            $localKeyHex,
        ),
        'cluster_key' => 'income::bik-income::eur::monthly',
        'created_at' => '2026-08-01T12:00:00Z',
        'updated_at' => '2026-08-01T12:00:00Z',
    ]);
}

it('mints a blind-index key alongside the first epoch', function (): void {
    $user = bikUser('bik-mint');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    expect($keyring->blindIndexKeyHex((int) $user->id, $session))->toBeString();
});

it('tags the fan-out wrap with its role so an epoch and a blind-index key cannot be confused', function (): void {
    $user = bikUser('bik-role');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $self = $identityService->generateAndPersist((int) $user->id, $session);
    [$senderId, $senderSecretHex] = bikConfirmedSender((int) $user->id);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $raw = random_bytes(32);
    $pub = sodium_hex2bin($self->x25519PublicKeyHex);

    $epochWrap = $rotation->buildGdkEpochWrap(7, $raw, new GdkWrapRecipient($self->deviceId, $pub), $senderId, $senderSecretHex);
    $blindWrap = $rotation->buildGdkEpochWrap(0, $raw, new GdkWrapRecipient($self->deviceId, $pub), $senderId, $senderSecretHex, GdkEpochWrapSignature::ROLE_BLIND_INDEX);

    expect($epochWrap)->not->toHaveKey('key_role');
    expect($blindWrap['key_role'])->toBe(GdkEpochWrapSignature::ROLE_BLIND_INDEX);
    expect($blindWrap['sig_hex'])->not->toBe($epochWrap['sig_hex']);
});

it('adopts a lower peer blind-index key on a device that has derived nothing yet', function (): void {
    $user = bikUser('bik-adopt');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);
    bikDeliver($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex);

    expect($keyring->blindIndexKeyHex((int) $user->id, $session))->toBe($peerKeyHex);
});

// The exploit path from the review: enable sync on an empty ledger, import,
// then pair weeks later. The sweep converted nothing, so a marker-based guard
// reads "has derived nothing" and hands the whole ledger's key away.
it('refuses a peer key on a device that enrolled empty and imported afterwards', function (): void {
    $user = bikUser('bik-enrol-then-import');
    /** @var Session $session */
    $session = app(Session::class);

    $localKeyHex = bikEnrolThenImport($user, $session);

    /** @var BlindIndexCodec $codec */
    $codec = app(BlindIndexCodec::class);
    expect($codec->hasSweptCounterpartyKeys((int) $user->id))->toBeTrue();
    expect(bikHoldsKeyedRows($user, $session))->toBeTrue();

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);
    bikDeliver($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex, senderKeyed: false);

    expect(app(GdkKeyringService::class)->blindIndexKeyHex((int) $user->id, $session))->toBe($localKeyHex);
});

// Both sides hold rows under different keys: neither can give way without
// orphaning its own digests, so both keep and the divergence is raised.
it('refuses, and does not half-resolve, when both devices already hold keyed rows', function (): void {
    $user = bikUser('bik-both-keyed');
    /** @var Session $session */
    $session = app(Session::class);

    $localKeyHex = bikEnrolThenImport($user, $session);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);
    bikDeliver($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex, senderKeyed: true);

    expect(app(GdkKeyringService::class)->blindIndexKeyHex((int) $user->id, $session))->toBe($localKeyHex);
});

// The side with rows wins, because it is the side with something to lose. The
// peer runs this same branch inverted and keeps its own.
it('adopts a peer key when the peer holds rows and this device does not', function (): void {
    $user = bikUser('bik-peer-keyed');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);
    bikDeliver($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex, senderKeyed: true);

    expect($keyring->blindIndexKeyHex((int) $user->id, $session))->toBe($peerKeyHex);
});

// Two fresh devices both fan out before either has adopted, so arrival order
// must not decide it. Lowest hex wins on both sides, whichever lands first.
it('breaks a tie between two unkeyed devices on an order neither arrival order nor which key is lower can change', function (): void {
    foreach ([true, false] as $peerKeyIsLower) {
        $user = bikUser('bik-tie-'.($peerKeyIsLower ? 'lower' : 'higher'));
        /** @var Session $session */
        $session = app(Session::class);

        /** @var GdkKeyringService $keyring */
        $keyring = app(GdkKeyringService::class);
        $keyring->generateAndPersist((int) $user->id, $session);
        $localKeyHex = (string) $keyring->blindIndexKeyHex((int) $user->id, $session);

        $peerKeyHex = $peerKeyIsLower
            ? str_repeat('0', 64)
            : str_repeat('f', 64);

        [$self, $senderId, $senderSecretHex] = bikInboundWrapParts($user, $session);
        bikDeliver($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex, senderKeyed: false);

        $expected = strcmp($peerKeyHex, $localKeyHex) < 0 ? $peerKeyHex : $localKeyHex;
        expect($keyring->blindIndexKeyHex((int) $user->id, $session))->toBe($expected);
    }
});

// Flipping the flag must not silently change which key the recipient keeps.
it('refuses a blind-index wrap whose keyed flag was flipped in transit', function (): void {
    $user = bikUser('bik-flip');
    /** @var Session $session */
    $session = app(Session::class);

    $localKeyHex = bikEnrolThenImport($user, $session);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $wrap = $rotation->buildGdkEpochWrap(
        0,
        sodium_hex2bin($peerKeyHex),
        new GdkWrapRecipient($self->deviceId, sodium_hex2bin($self->x25519PublicKeyHex)),
        $senderId,
        $senderSecretHex,
        GdkEpochWrapSignature::ROLE_BLIND_INDEX,
        false,
    );
    $wrap['sender_holds_keyed_rows'] = true;

    app(GdkEpochControlHandler::class)->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    expect(app(GdkKeyringService::class)->blindIndexKeyHex((int) $user->id, $session))->toBe($localKeyHex);
});

// A phone that enables encryption during pairing sweeps an empty database. The
// sweep marker is set either way; what decides adoption is whether the device
// holds keyed rows, and here it holds none.
it('still adopts a peer key after a sweep that had no rows to convert', function (): void {
    $user = bikUser('bik-empty-sweep');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var EncryptionMigrationService $migration */
    $migration = app(EncryptionMigrationService::class);
    $migration->migrate($user, $session);

    /** @var BlindIndexCodec $codec */
    $codec = app(BlindIndexCodec::class);
    expect($codec->isEnrolled((int) $user->id))->toBeTrue();
    expect($codec->hasSweptCounterpartyKeys((int) $user->id))->toBeTrue();
    expect(bikHoldsKeyedRows($user, $session))->toBeFalse();

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);
    bikDeliver($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex);

    expect(app(GdkKeyringService::class)->blindIndexKeyHex((int) $user->id, $session))->toBe($peerKeyHex);
});

it('never adopts a blind-index key as an epoch', function (): void {
    $user = bikUser('bik-not-epoch');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);
    bikDeliver($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex);

    expect($keyring->loadKeyring((int) $user->id, $session)->keyFor(0))->toBeNull();
});

// The signature covers the role, so a wrap re-labelled as an epoch key no
// longer verifies and is refused before any seal is opened.
it('refuses a blind-index wrap whose role was stripped in transit', function (): void {
    $user = bikUser('bik-strip');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);
    $localKeyHex = $keyring->blindIndexKeyHex((int) $user->id, $session);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $wrap = $rotation->buildGdkEpochWrap(
        0,
        sodium_hex2bin($peerKeyHex),
        new GdkWrapRecipient($self->deviceId, sodium_hex2bin($self->x25519PublicKeyHex)),
        $senderId,
        $senderSecretHex,
        GdkEpochWrapSignature::ROLE_BLIND_INDEX,
    );
    unset($wrap['key_role']);

    app(GdkEpochControlHandler::class)->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    expect($keyring->loadKeyring((int) $user->id, $session)->keyFor(0))->toBeNull();
    expect($keyring->blindIndexKeyHex((int) $user->id, $session))->toBe($localKeyHex);
});

// Epoch ids are random and no wrap says which is current, so the recipient's
// current_epoch is decided by arrival order. A fan-out that emitted them in
// keyring order could settle a joining device on a retired epoch — the one a
// revoked device still holds.
it('fans out the current epoch last, so a joining device settles on it', function (): void {
    $user = bikUser('bik-order');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $first = $keyring->generateAndPersist((int) $user->id, $session);

    // A rotation appends a second epoch and makes it current. The retired one
    // stays in the keyring because it still decrypts history.
    $current = new GdkEpoch(epochId: 777, keyHex: bin2hex(random_bytes(32)));
    $keyring->appendEpoch((int) $user->id, $current, $session);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $self = $identityService->generateAndPersist((int) $user->id, $session);

    $recipientId = bikConfirmedRecipient((int) $user->id);

    app(GdkRotationService::class)->fanOutAllEpochsToDevice((int) $user->id, $recipientId, $session);

    $epochOrder = [];
    foreach (app(RelayMailbox::class)->drain(bikRecipientDeviceId((int) $user->id, $recipientId), 50) as $row) {
        $decoded = json_decode(is_string($row->blob) ? $row->blob : '', true);
        if (is_array($decoded) && GdkEpochWrapSignature::carriesEpoch($decoded) && is_int($decoded['epoch_id'] ?? null)) {
            $epochOrder[] = $decoded['epoch_id'];
        }
    }

    expect($epochOrder)->toHaveCount(2);
    expect($epochOrder[0])->toBe($first->epochId);
    expect($epochOrder[1])->toBe(777);
});

// The inverse of the reported case. A locked device applies a peer's rows
// during catch-up — counterparty_normalized is a non-sensitive
// _create_required column — and a probe that only measured shape then read
// those digests as its own work and refused the key its ledger is written
// under. The wrap is deleted next, so nothing re-sends it.
it('adopts a peer key on a device whose only digests arrived from that peer', function (): void {
    $user = bikUser('bik-peer-rows-only');
    /** @var Session $session */
    $session = app(Session::class);

    app(EncryptionMigrationService::class)->migrate($user, $session);

    // Not this device's key: the peer's. Deterministically above any minted
    // key so lowest-hex-wins cannot be what carries the assertion.
    bikSeedPeerKeyedRows($user, str_repeat('f', 64));

    expect(bikHoldsKeyedRows($user, $session))->toBeFalse();

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);
    $outcome = bikDeliverOutcome($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex, senderKeyed: true);

    expect($outcome)->toBe(GdkWrapOutcome::Applied);
    expect(app(GdkKeyringService::class)->blindIndexKeyHex((int) $user->id, $session))->toBe($peerKeyHex);
});

// An income-only ledger keys nothing in transactions: the payer has no
// free-form name, so counterparty_normalized is the sentinel and the only
// keyed value the device owns is the payer IBAN on the series.
it('holds its ground for an income-only ledger whose one keyed value is the payer IBAN', function (): void {
    $user = bikUser('bik-income-only');
    /** @var Session $session */
    $session = app(Session::class);

    app(EncryptionMigrationService::class)->migrate($user, $session);
    $localKeyHex = (string) app(GdkKeyringService::class)->blindIndexKeyHex((int) $user->id, $session);

    bikSeedIncomeOnlyLedger($user, $localKeyHex);

    expect(bikHoldsKeyedRows($user, $session))->toBeTrue();

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);
    bikDeliver($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex, senderKeyed: false);

    expect(app(GdkKeyringService::class)->blindIndexKeyHex((int) $user->id, $session))->toBe($localKeyHex);
});

// Both sides hold keyed rows, so neither adopts — but the wrap is the only
// copy of the peer's index key, and a re-derive is what would resolve this.
it('keeps the peer wrap when both devices hold keyed rows, rather than retiring the key a recovery needs', function (): void {
    $user = bikUser('bik-retained');
    /** @var Session $session */
    $session = app(Session::class);

    $localKeyHex = bikEnrolThenImport($user, $session);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);
    $outcome = bikDeliverOutcome($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex, senderKeyed: true);

    expect($outcome)->toBe(GdkWrapOutcome::Retained);
    expect($outcome->consumesCarrier())->toBeFalse();
    expect(app(GdkKeyringService::class)->blindIndexKeyHex((int) $user->id, $session))->toBe($localKeyHex);
});

// An epoch wrap does not sign this field and must never read it: a party that
// can append one JSON key to a stored blob could otherwise retire an epoch
// key, and every op-log row sealed under it becomes unreadable forever.
it('ignores a field on an epoch wrap that no signature covers, instead of retiring the key over it', function (): void {
    $user = bikUser('bik-unsigned-field');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    [$self, $senderId, $senderSecretHex] = bikInboundWrapParts($user, $session);

    $wrap = app(GdkRotationService::class)->buildGdkEpochWrap(
        4242,
        random_bytes(32),
        new GdkWrapRecipient($self->deviceId, sodium_hex2bin($self->x25519PublicKeyHex)),
        $senderId,
        $senderSecretHex,
    );
    $wrap['sender_holds_keyed_rows'] = 'x';

    $outcome = app(GdkEpochControlHandler::class)->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    expect($outcome)->toBe(GdkWrapOutcome::Applied);
    expect($keyring->loadKeyring((int) $user->id, $session)->keyFor(4242))->toBeString();
});

// Whether the recipient has recorded the sender as confirmed when the wrap
// lands is an ordering question during pairing, not a permanent fact.
it('holds a wrap from a sender it has not confirmed yet, rather than retiring it', function (): void {
    $user = bikUser('bik-unconfirmed-sender');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);

    app(DatabaseManager::class)->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', $senderId)
        ->update(['confirmed_at' => null]);

    $outcome = bikDeliverOutcome($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex);

    expect($outcome)->toBe(GdkWrapOutcome::Deferred);
    expect($outcome->consumesCarrier())->toBeFalse();
});

// The reserved id is signed like every other field, so a blind-index wrap
// carrying a real epoch id was built that way rather than mutated into it.
it('refuses a blind-index wrap carrying an epoch id other than the reserved one', function (): void {
    $user = bikUser('bik-epoch-id');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);
    $localKeyHex = $keyring->blindIndexKeyHex((int) $user->id, $session);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);

    $wrap = app(GdkRotationService::class)->buildGdkEpochWrap(
        91827,
        sodium_hex2bin($peerKeyHex),
        new GdkWrapRecipient($self->deviceId, sodium_hex2bin($self->x25519PublicKeyHex)),
        $senderId,
        $senderSecretHex,
        GdkEpochWrapSignature::ROLE_BLIND_INDEX,
    );

    $outcome = app(GdkEpochControlHandler::class)->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    expect($outcome)->toBe(GdkWrapOutcome::Refused);
    expect($keyring->blindIndexKeyHex((int) $user->id, $session))->toBe($localKeyHex);
    expect($keyring->loadKeyring((int) $user->id, $session)->keyFor(91827))->toBeNull();
});

// The message is separator-joined and only the trailing plaintext varies, so
// a domain carrying the separator would make two different inputs hash alike.
it('refuses to derive under a domain the registry does not declare', function (): void {
    $user = bikUser('bik-domain');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);
    $keyHex = (string) $keyring->blindIndexKeyHex((int) $user->id, $session);

    /** @var BlindIndexCodec $codec */
    $codec = app(BlindIndexCodec::class);

    expect(fn () => $codec->deriveWithKey('a|1|x', 'Q', (int) $user->id, $keyHex))
        ->toThrow(LogicException::class);
});

// Every domain the ledger derives under must be one the registry declares, or
// the guard above turns a live write into a runtime failure.
it('declares every domain the ledger derives under', function (): void {
    expect(SensitiveFieldRegistry::blindIndexDomains())
        ->toContain(CounterpartyKey::DOMAIN)
        ->toContain(CounterpartyKey::DOMAIN_IBAN);
});

// The method's own comment says every variable-length field is length-prefixed
// so a separator in one cannot shift another. The role was the exception, safe
// only because a second class allowlisted it before the message was built —
// and that allowlist now runs after the signature, where it cannot help.
it('length-prefixes the role term the way its own comment says every variable-length field is', function (): void {
    $message = GdkEpochWrapSignature::signingMessage(
        GdkEpochWrapSignature::BLIND_INDEX_EPOCH_ID,
        'sealed',
        'recipient',
        'sender',
        GdkEpochWrapSignature::ROLE_BLIND_INDEX,
        false,
    );

    expect($message)->toContain('role:'.strlen(GdkEpochWrapSignature::ROLE_BLIND_INDEX).':'.GdkEpochWrapSignature::ROLE_BLIND_INDEX);
});
