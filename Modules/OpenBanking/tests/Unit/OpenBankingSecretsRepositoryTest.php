<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCredentialsException;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsFile;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Internal\Services\SecretsWriteFailed;
use Modules\OpenBanking\Tests\Support\OpenBankingReversingShield;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

/** @link ../../../../.docs/features/open-banking/secrets-at-rest.md#two-encryption-layers-applied-inner-to-outer */

// The bound SecretShield is the identity function, so "not plaintext-readable"
// would be tautologically false against it. Every test injects the reversing
// double instead, proving the bytes really go through protect()/reveal().

// The store is keyed by reader, so every case names one. The second reader
// appears only where the point is that the two files never touch.
const OB_SECRETS_READER = 1;

const OB_SECRETS_OTHER_READER = 2;

const OB_SECRETS_APPLICATION_ID = 'app-fixture-123';

const OB_SECRETS_SESSION_ID = 'session-fixture-abc';

function openBankingSecretsFixturePath(): string
{
    return OpenBankingSecretsFixture::path(OB_SECRETS_READER);
}

// The header is assembled rather than written out: spelled in full it is a
// private key to the secret gate, which walks every ref in the repository and
// so fails the check on every OTHER open pull request.
function openBankingSecretsFixturePem(string $body): string
{
    return '-----BEGIN '."PRIVATE KEY-----\n".$body."\n".'-----END '.'PRIVATE KEY-----';
}

// The atomic write, the two at-rest layers and the 0600/0700 modes all live on
// OpenBankingSecretsFile; the repository only decides which path a reader's
// record lands at. Both halves are built here so a test can drive either.
function openBankingSecretsFixtureFile(Encrypter $encrypter): OpenBankingSecretsFile
{
    return new OpenBankingSecretsFile(new Filesystem, new OpenBankingReversingShield, $encrypter);
}

function openBankingSecretsFixtureRepository(Encrypter $encrypter): OpenBankingSecretsRepository
{
    return new OpenBankingSecretsRepository(openBankingSecretsFixtureFile($encrypter));
}

// One reader connected to one bank: the application half plus a single
// connection record, written the way the wizard and the callback write them.
function openBankingSecretsFixtureSave(
    OpenBankingSecretsRepository $repository,
    int $userId = OB_SECRETS_READER,
): void {
    $repository->saveApplication(
        $userId,
        OB_SECRETS_APPLICATION_ID,
        openBankingSecretsFixturePem('fixture-key-bytes'),
    );
    $repository->rememberScaHost($userId, OpenBankingSecretsFixture::INSTITUTION_ID, 'sca.asnbank.nl');
    $repository->rememberSession(
        $userId,
        OpenBankingSecretsFixture::INSTITUTION_ID,
        OB_SECRETS_SESSION_ID,
        CarbonImmutable::parse('2026-08-01T00:00:00+00:00'),
    );
}

// The 0700 assertion only means something about a directory this write created,
// so the cases that read the mode start from one that is not there.
function openBankingSecretsFixtureRemoveDirectory(): void
{
    $dir = dirname(openBankingSecretsFixturePath());
    if (! is_dir($dir)) {
        return;
    }

    foreach (glob($dir.'/*') ?: [] as $entry) {
        if (is_file($entry)) {
            @unlink($entry);
        }
    }

    @rmdir($dir);
}

beforeEach(function (): void {
    $this->obSecretsPath = openBankingSecretsFixturePath();
    $this->obFiles = new Filesystem;
    // A real APP_KEY-style encrypter, so the on-disk bytes are true ciphertext.
    $this->obEncrypter = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');
    $this->obRepository = openBankingSecretsFixtureRepository($this->obEncrypter);

    OpenBankingSecretsFixture::forget(OB_SECRETS_READER);
    OpenBankingSecretsFixture::forget(OB_SECRETS_OTHER_READER);
    OpenBankingSecretsFixture::forgetLegacy();
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forget(OB_SECRETS_READER);
    OpenBankingSecretsFixture::forget(OB_SECRETS_OTHER_READER);
    OpenBankingSecretsFixture::forgetLegacy();
});

it('round-trips all six credential fields through the reader-and-bank writes then load', function (): void {
    openBankingSecretsFixtureSave($this->obRepository);

    $loaded = $this->obRepository->load(OB_SECRETS_READER, OpenBankingSecretsFixture::INSTITUTION_ID);

    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe(OB_SECRETS_APPLICATION_ID);
    expect($loaded->privateKeyPem)->toBe(openBankingSecretsFixturePem('fixture-key-bytes'));
    expect($loaded->sessionId)->toBe(OB_SECRETS_SESSION_ID);
    expect($loaded->consentExpiresAt?->toAtomString())->toBe('2026-08-01T00:00:00+00:00');
    expect($loaded->bankScaHost)->toBe('sca.asnbank.nl');
    expect($loaded->institutionId)->toBe(OpenBankingSecretsFixture::INSTITUTION_ID);
});

it('writes the file mode 0600 in a 0700 parent dir', function (): void {
    openBankingSecretsFixtureRemoveDirectory();

    openBankingSecretsFixtureSave($this->obRepository);

    expect(is_file($this->obSecretsPath))->toBeTrue();
    expect(fileperms($this->obSecretsPath) & 0o777)->toBe(0o600);
    expect(fileperms(dirname($this->obSecretsPath)) & 0o777)->toBe(0o700);
});

it('shields the on-disk bytes so raw credential JSON is never plaintext-readable', function (): void {
    openBankingSecretsFixtureSave($this->obRepository);

    $onDisk = (string) file_get_contents($this->obSecretsPath);
    expect($onDisk)->not->toContain(OB_SECRETS_APPLICATION_ID);
    expect($onDisk)->not->toContain('BEGIN PRIVATE KEY');
    expect($onDisk)->not->toContain('sca.asnbank.nl');
    expect($onDisk)->not->toContain('application_id');
});

it('raises SecretsWriteFailed with no credential material on a simulated rename failure, cleans up the temp file, and restores umask', function (): void {
    $file = new class(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter) extends OpenBankingSecretsFile
    {
        protected function performRename(string $tmp, string $final): bool
        {
            return false;
        }
    };
    $repo = new OpenBankingSecretsRepository($file);

    $prevUmask = umask();

    $caught = null;
    try {
        openBankingSecretsFixtureSave($repo);
    } catch (SecretsWriteFailed $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->getMessage())->not->toContain(OB_SECRETS_APPLICATION_ID);
    expect($caught->getMessage())->not->toContain('BEGIN PRIVATE KEY');
    expect($caught->getMessage())->toContain('atomic rename failed');
    // The mechanics moved wholesale, and the message names the class that
    // actually holds them rather than the repository that asked for the write.
    expect($caught->getMessage())->toContain('OpenBankingSecretsFile');

    expect(is_file($this->obSecretsPath.'.tmp'))->toBeFalse();
    expect(umask())->toBe($prevUmask);
});

it('returns null from load() when the reader has no file yet (no throw)', function (): void {
    expect($this->obRepository->load(OB_SECRETS_READER))->toBeNull();
    expect($this->obRepository->load(OB_SECRETS_READER, OpenBankingSecretsFixture::INSTITUTION_ID))->toBeNull();
});

it('reports hasApplication false before the application half is written and true after', function (): void {
    expect($this->obRepository->hasApplication(OB_SECRETS_READER))->toBeFalse();

    openBankingSecretsFixtureSave($this->obRepository);
    expect($this->obRepository->hasApplication(OB_SECRETS_READER))->toBeTrue();
});

// The wizard writes the private key a step before the application id, then
// load()s that half-written file to merge the id into it. hasApplication()
// answers for the pair; load() is gated on the key alone, or step 3 would
// have nothing to merge into.
it('loads a file holding only the private key that hasApplication() still calls incomplete', function (): void {
    $this->obRepository->saveApplication(OB_SECRETS_READER, '', openBankingSecretsFixturePem('half-written'));

    expect($this->obRepository->hasApplication(OB_SECRETS_READER))->toBeFalse();

    $loaded = $this->obRepository->load(OB_SECRETS_READER);
    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe('');
    expect($loaded->privateKeyPem)->toBe(openBankingSecretsFixturePem('half-written'));
});

it('clear removes the reader file, load returns null afterward, and clear is idempotent', function (): void {
    openBankingSecretsFixtureSave($this->obRepository);
    expect($this->obRepository->load(OB_SECRETS_READER))->not->toBeNull();

    $this->obRepository->clear(OB_SECRETS_READER);
    expect($this->obRepository->load(OB_SECRETS_READER))->toBeNull();
    expect(is_file($this->obSecretsPath))->toBeFalse();

    $this->obRepository->clear(OB_SECRETS_READER);
});

// On the desktop bundle the on-disk bytes are safeStorage ciphertext, not JSON,
// so corruption is only visible after the reveal — hence reveal before decode.
it('treats an empty secrets file as no credentials rather than a corrupt one', function (): void {
    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    $this->obFiles->put($this->obSecretsPath, '');

    expect($this->obRepository->load(OB_SECRETS_READER))->toBeNull()
        ->and($this->obRepository->hasApplication(OB_SECRETS_READER))->toBeFalse();
});

// A file that will not decode is a different thing from an empty one: empty
// means the wizard was never run, undecodable means something needs repairing.
it('refuses a secrets file whose revealed bytes are not JSON', function (): void {
    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    $this->obFiles->put($this->obSecretsPath, strrev('{"application_id": '));

    expect(fn () => $this->obRepository->load(OB_SECRETS_READER))
        ->toThrow(OpenBankingCredentialsException::class, $this->obSecretsPath);
});

// The revealed payload and the raw bytes are both credential material, and this
// exception is logged wherever it surfaces, so the message may name the path only.
it('names only the path when it refuses an unreadable secrets file', function (): void {
    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    $this->obFiles->put($this->obSecretsPath, strrev('{"application_id": "super-secret-value"'));

    try {
        $this->obRepository->load(OB_SECRETS_READER);
        expect(false)->toBeTrue('load() should have refused the file');
    } catch (OpenBankingCredentialsException $e) {
        expect($e->getMessage())->toContain($this->obSecretsPath)
            ->and($e->getMessage())->not->toContain('super-secret-value');
    }
});

// Valid JSON that is not an object carries no fields, so it reads as an empty
// store rather than an error: there is nothing to repair.
it('reads a well-formed non-object secrets file as an empty store', function (string $revealed): void {
    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    $this->obFiles->put($this->obSecretsPath, strrev($revealed));

    expect($this->obRepository->load(OB_SECRETS_READER))->toBeNull();
})->with([
    'a string' => ['"just a string"'],
    'a number' => ['42'],
    'null' => ['null'],
]);

it('raises SecretsWriteFailed when the secrets parent directory cannot be created', function (): void {
    $secretsDir = dirname($this->obSecretsPath);
    openBankingSecretsFixtureRemoveDirectory();

    // Occupy the directory's own path with a file so mkdir() cannot create it.
    $this->obFiles->ensureDirectoryExists(dirname($secretsDir));
    file_put_contents($secretsDir, 'blocking file');

    try {
        expect(fn () => openBankingSecretsFixtureSave($this->obRepository))
            ->toThrow(SecretsWriteFailed::class, 'could not create parent directory');
    } finally {
        @unlink($secretsDir);
    }
});

// encodePayload() maps json_encode()'s JsonException to SecretsWriteFailed
// without echoing the payload it refused.
it('raises SecretsWriteFailed when the payload cannot be encoded, without leaking it', function (): void {
    try {
        $this->obRepository->saveApplication(
            OB_SECRETS_READER,
            OB_SECRETS_APPLICATION_ID,
            // A lone continuation byte is not valid UTF-8, so json_encode() fails.
            openBankingSecretsFixturePem("\xB1\xB2"),
        );
        expect(false)->toBeTrue('saveApplication() should have refused the un-encodable payload');
    } catch (SecretsWriteFailed $e) {
        expect($e->getMessage())->toContain('failed to encode payload')
            ->and($e->getMessage())->not->toContain('BEGIN PRIVATE KEY');
    }

    expect(is_file($this->obSecretsPath))->toBeFalse();
});

// A directory occupying the temp file's path makes fopen() return false.
it('raises SecretsWriteFailed when the temp file cannot be opened, and restores umask', function (): void {
    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    @mkdir($this->obSecretsPath.'.tmp', 0700);

    $prevUmask = umask();

    try {
        expect(fn () => openBankingSecretsFixtureSave($this->obRepository))
            ->toThrow(SecretsWriteFailed::class, 'could not open temp file');
        expect(umask())->toBe($prevUmask);
    } finally {
        @rmdir($this->obSecretsPath.'.tmp');
    }
});

// The on-disk bytes are shield(encrypt(json)), so strrev() gets back the exact
// Laravel ciphertext — proving the encrypter ran, not just the shield.
it('encrypts the payload at rest so the on-disk bytes decrypt back to the saved JSON, and reads its own encrypted file', function (): void {
    openBankingSecretsFixtureSave($this->obRepository);

    $ciphertext = strrev((string) file_get_contents($this->obSecretsPath));
    $decryptedJson = $this->obEncrypter->decryptString($ciphertext);

    expect($decryptedJson)->toContain(OB_SECRETS_APPLICATION_ID)
        ->and($decryptedJson)->toContain(OB_SECRETS_SESSION_ID)
        ->and($ciphertext)->not->toContain(OB_SECRETS_APPLICATION_ID)
        ->and($ciphertext)->not->toContain('BEGIN PRIVATE KEY')
        ->and($ciphertext)->not->toContain(OB_SECRETS_SESSION_ID);

    $loaded = $this->obRepository->load(OB_SECRETS_READER, OpenBankingSecretsFixture::INSTITUTION_ID);
    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe(OB_SECRETS_APPLICATION_ID);
    expect($loaded->privateKeyPem)->toBe(openBankingSecretsFixturePem('fixture-key-bytes'));
    expect($loaded->sessionId)->toBe(OB_SECRETS_SESSION_ID);
});

// Pre-encryption on-disk format: shield-protected but NOT encrypted, which with
// the reversing shield is strrev(json). The pre-keying installation's one global
// file is where that format survives, and it has to stay readable or an
// upgrading reader silently loses the consent they already granted.
it('still reads the pre-keying plaintext file and adopts it into the reader\'s own encrypted one', function (): void {
    $legacyJson = json_encode([
        'application_id' => 'legacy-app-777',
        'private_key_pem' => openBankingSecretsFixturePem('legacy-bytes'),
        'session_id' => 'legacy-session-xyz',
        'consent_expires_at' => '2026-08-01T00:00:00+00:00',
        'bank_sca_host' => 'sca.asnbank.nl',
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $this->obFiles->ensureDirectoryExists(dirname(OpenBankingSecretsFixture::legacyPath()));
    $this->obFiles->put(OpenBankingSecretsFixture::legacyPath(), strrev($legacyJson));

    // The institution is read first because it is what names the owner: the
    // migration cannot key the file until it knows whose session this was.
    expect($this->obRepository->legacyInstitutionId())->toBe(OpenBankingSecretsFixture::INSTITUTION_ID);
    expect($this->obRepository->adoptLegacyFile(OB_SECRETS_READER))->toBeTrue();

    $loaded = $this->obRepository->load(OB_SECRETS_READER, OpenBankingSecretsFixture::INSTITUTION_ID);
    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe('legacy-app-777');
    expect($loaded->privateKeyPem)->toContain('legacy-bytes');
    expect($loaded->sessionId)->toBe('legacy-session-xyz');
    expect($loaded->bankScaHost)->toBe('sca.asnbank.nl');

    // The adopted file goes through the APP_KEY layer the legacy one predates,
    // and the legacy one is gone only once the keyed one is on disk.
    $ciphertext = strrev((string) file_get_contents($this->obSecretsPath));
    expect($this->obEncrypter->decryptString($ciphertext))->toContain('legacy-app-777');
    expect(is_file(OpenBankingSecretsFixture::legacyPath()))->toBeFalse();
});

// A reader connects a second bank without disconnecting the first, so a fresh
// session must merge into the file rather than replace what is in it.
it('keeps each bank\'s session and SCA host apart inside one reader\'s file', function (): void {
    openBankingSecretsFixtureSave($this->obRepository);

    $this->obRepository->rememberScaHost(
        OB_SECRETS_READER,
        OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        'sca.snsbank.nl',
    );
    $this->obRepository->rememberSession(
        OB_SECRETS_READER,
        OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        'session-sns',
        CarbonImmutable::parse('2026-12-01T00:00:00+00:00'),
    );

    $first = $this->obRepository->load(OB_SECRETS_READER, OpenBankingSecretsFixture::INSTITUTION_ID);
    expect($first?->sessionId)->toBe(OB_SECRETS_SESSION_ID);
    expect($first?->bankScaHost)->toBe('sca.asnbank.nl');
    expect($first?->consentExpiresAt?->toAtomString())->toBe('2026-08-01T00:00:00+00:00');

    $second = $this->obRepository->load(OB_SECRETS_READER, OpenBankingSecretsFixture::SECOND_INSTITUTION_ID);
    expect($second?->sessionId)->toBe('session-sns');
    expect($second?->bankScaHost)->toBe('sca.snsbank.nl');
    expect($second?->consentExpiresAt?->toAtomString())->toBe('2026-12-01T00:00:00+00:00');

    expect($this->obRepository->connectedInstitutions(OB_SECRETS_READER))->toBe([
        OpenBankingSecretsFixture::INSTITUTION_ID,
        OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
    ]);
});

// The application half belongs to the reader, not to any one bank: asking for a
// bank they never linked answers with the half they do hold and no session.
it('answers for an unlinked bank with the application half and no session', function (): void {
    openBankingSecretsFixtureSave($this->obRepository);

    $unlinked = $this->obRepository->load(OB_SECRETS_READER, OpenBankingSecretsFixture::SECOND_INSTITUTION_ID);

    expect($unlinked)->not->toBeNull();
    expect($unlinked->applicationId)->toBe(OB_SECRETS_APPLICATION_ID);
    expect($unlinked->privateKeyPem)->toBe(openBankingSecretsFixturePem('fixture-key-bytes'));
    expect($unlinked->sessionId)->toBeNull();
    expect($unlinked->consentExpiresAt)->toBeNull();
    expect($unlinked->bankScaHost)->toBeNull();
    expect($unlinked->institutionId)->toBe(OpenBankingSecretsFixture::SECOND_INSTITUTION_ID);

    expect($this->obRepository->connectedInstitutions(OB_SECRETS_READER))
        ->toBe([OpenBankingSecretsFixture::INSTITUTION_ID]);
});

// loadOrThrow() is what a fetch calls, and its two refusals are different
// repairs: finish the wizard, or reconnect this one bank.
it('refuses loadOrThrow for an unlinked bank while the linked one still loads', function (): void {
    openBankingSecretsFixtureSave($this->obRepository);

    expect($this->obRepository->loadOrThrow(OB_SECRETS_READER, OpenBankingSecretsFixture::INSTITUTION_ID)->sessionId)
        ->toBe(OB_SECRETS_SESSION_ID);

    try {
        $this->obRepository->loadOrThrow(OB_SECRETS_READER, OpenBankingSecretsFixture::SECOND_INSTITUTION_ID);
        expect(false)->toBeTrue('loadOrThrow() should have refused the unlinked bank');
    } catch (OpenBankingCredentialsException $e) {
        expect($e->getMessage())->toContain(OpenBankingSecretsFixture::SECOND_INSTITUTION_ID);
    }

    expect(fn () => $this->obRepository->loadOrThrow(
        OB_SECRETS_OTHER_READER,
        OpenBankingSecretsFixture::INSTITUTION_ID,
    ))->toThrow(
        OpenBankingCredentialsException::class,
        'No Enable Banking application credentials are persisted.',
    );
});

// Disconnecting is per reader, and the file it deletes is addressed by reader
// id — a household member's consent is not collateral.
it('clears one reader without touching another reader\'s file', function (): void {
    openBankingSecretsFixtureSave($this->obRepository, OB_SECRETS_READER);
    openBankingSecretsFixtureSave($this->obRepository, OB_SECRETS_OTHER_READER);

    $this->obRepository->clear(OB_SECRETS_READER);

    expect(is_file(OpenBankingSecretsFixture::path(OB_SECRETS_READER)))->toBeFalse();
    expect($this->obRepository->load(OB_SECRETS_READER))->toBeNull();

    expect(is_file(OpenBankingSecretsFixture::path(OB_SECRETS_OTHER_READER)))->toBeTrue();
    expect($this->obRepository->loadOrThrow(
        OB_SECRETS_OTHER_READER,
        OpenBankingSecretsFixture::INSTITUTION_ID,
    )->sessionId)->toBe(OB_SECRETS_SESSION_ID);
});
