<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCredentialsException;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Internal\Services\SecretsWriteFailed;

/** @link ../../../../.docs/features/open-banking/secrets-at-rest.md#two-encryption-layers-applied-inner-to-outer */

// The bound SecretShield is the identity function, so "not plaintext-readable"
// would be tautologically false against it. Every test injects the reversing
// double instead, proving the bytes really go through protect()/reveal().

final class OpenBankingReversingShield implements SecretShield
{
    public function protect(string $plaintext): string
    {
        return strrev($plaintext);
    }

    public function reveal(string $shielded): string
    {
        return strrev($shielded);
    }
}

function openBankingSecretsFixturePath(): string
{
    return storage_path('app/secrets/open-banking.json');
}

function openBankingSecretsFixtureCredentials(): OpenBankingCredentials
{
    return new OpenBankingCredentials(
        applicationId: 'app-fixture-123',
        privateKeyPem: "-----BEGIN PRIVATE KEY-----\nfixture-key-bytes\n-----END PRIVATE KEY-----",
        sessionId: 'session-fixture-abc',
        consentExpiresAt: CarbonImmutable::parse('2026-08-01T00:00:00+00:00'),
        bankScaHost: 'sca.asnbank.nl',
        institutionId: 'ASNBNL21',
    );
}

beforeEach(function (): void {
    $this->obSecretsPath = openBankingSecretsFixturePath();
    $this->obFiles = new Filesystem;
    // A real APP_KEY-style encrypter, so the on-disk bytes are true ciphertext.
    $this->obEncrypter = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');
    if ($this->obFiles->exists($this->obSecretsPath)) {
        $this->obFiles->delete($this->obSecretsPath);
    }
    if ($this->obFiles->exists($this->obSecretsPath.'.tmp')) {
        $this->obFiles->delete($this->obSecretsPath.'.tmp');
    }
});

afterEach(function (): void {
    if ($this->obFiles->exists($this->obSecretsPath)) {
        $this->obFiles->delete($this->obSecretsPath);
    }
    if ($this->obFiles->exists($this->obSecretsPath.'.tmp')) {
        $this->obFiles->delete($this->obSecretsPath.'.tmp');
    }
});

it('round-trips all six credential fields through save then load', function (): void {
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);
    $credentials = openBankingSecretsFixtureCredentials();

    $repo->save($credentials);
    $loaded = $repo->load();

    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe('app-fixture-123');
    expect($loaded->privateKeyPem)->toBe($credentials->privateKeyPem);
    expect($loaded->sessionId)->toBe('session-fixture-abc');
    expect($loaded->consentExpiresAt?->toAtomString())->toBe('2026-08-01T00:00:00+00:00');
    expect($loaded->bankScaHost)->toBe('sca.asnbank.nl');
    expect($loaded->institutionId)->toBe('ASNBNL21');
});

it('writes the file mode 0600 in a 0700 parent dir', function (): void {
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);
    $repo->save(openBankingSecretsFixtureCredentials());

    expect(is_file($this->obSecretsPath))->toBeTrue();
    expect(fileperms($this->obSecretsPath) & 0o777)->toBe(0o600);
    expect(fileperms(dirname($this->obSecretsPath)) & 0o777)->toBe(0o700);
});

it('shields the on-disk bytes so raw credential JSON is never plaintext-readable', function (): void {
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);
    $repo->save(openBankingSecretsFixtureCredentials());

    $onDisk = (string) file_get_contents($this->obSecretsPath);
    expect($onDisk)->not->toContain('app-fixture-123');
    expect($onDisk)->not->toContain('BEGIN PRIVATE KEY');
    expect($onDisk)->not->toContain('sca.asnbank.nl');
    expect($onDisk)->not->toContain('application_id');
});

it('raises SecretsWriteFailed with no credential material on a simulated rename failure, cleans up the temp file, and restores umask', function (): void {
    $repo = new class(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter) extends OpenBankingSecretsRepository
    {
        protected function performRename(string $tmp, string $final): bool
        {
            return false;
        }
    };

    $prevUmask = umask();

    $caught = null;
    try {
        $repo->save(openBankingSecretsFixtureCredentials());
    } catch (SecretsWriteFailed $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->getMessage())->not->toContain('app-fixture-123');
    expect($caught->getMessage())->not->toContain('BEGIN PRIVATE KEY');
    expect($caught->getMessage())->toContain('atomic rename failed');

    expect(is_file($this->obSecretsPath.'.tmp'))->toBeFalse();
    expect(umask())->toBe($prevUmask);
});

it('returns null from load() when the file is missing (no throw)', function (): void {
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);
    expect($repo->load())->toBeNull();
});

it('reports hasApplication false before save and true after', function (): void {
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);
    expect($repo->hasApplication())->toBeFalse();

    $repo->save(openBankingSecretsFixtureCredentials());
    expect($repo->hasApplication())->toBeTrue();
});

it('clear removes the credential file, load returns null afterward, and clear is idempotent', function (): void {
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);
    $repo->save(openBankingSecretsFixtureCredentials());
    expect($repo->load())->not->toBeNull();

    $repo->clear();
    expect($repo->load())->toBeNull();
    expect(is_file($this->obSecretsPath))->toBeFalse();

    $repo->clear();
});

// On the desktop bundle the on-disk bytes are safeStorage ciphertext, not JSON,
// so corruption is only visible after the reveal — hence reveal before decode.
it('treats an empty secrets file as no credentials rather than a corrupt one', function (): void {
    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    $this->obFiles->put($this->obSecretsPath, '');

    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);

    expect($repo->load())->toBeNull()
        ->and($repo->hasApplication())->toBeFalse();
});

// A file that will not decode is a different thing from an empty one: empty
// means the wizard was never run, undecodable means something needs repairing.
it('refuses a secrets file whose revealed bytes are not JSON', function (): void {
    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    $this->obFiles->put($this->obSecretsPath, strrev('{"applicationId": '));

    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);

    expect(fn () => $repo->load())
        ->toThrow(OpenBankingCredentialsException::class, $this->obSecretsPath);
});

// The revealed payload and the raw bytes are both credential material, and this
// exception is logged wherever it surfaces, so the message may name the path only.
it('names only the path when it refuses an unreadable secrets file', function (): void {
    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    $this->obFiles->put($this->obSecretsPath, strrev('{"applicationId": "super-secret-value"'));

    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);

    try {
        $repo->load();
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

    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);

    expect($repo->load())->toBeNull();
})->with([
    'a string' => ['"just a string"'],
    'a number' => ['42'],
    'null' => ['null'],
]);

it('raises SecretsWriteFailed when the secrets parent directory cannot be created', function (): void {
    $secretsDir = dirname($this->obSecretsPath);
    if (is_dir($secretsDir)) {
        foreach (glob($secretsDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($secretsDir);
    }
    // Occupy the directory's own path with a file so mkdir() cannot create it.
    $this->obFiles->ensureDirectoryExists(dirname($secretsDir));
    file_put_contents($secretsDir, 'blocking file');

    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);

    try {
        expect(fn () => $repo->save(openBankingSecretsFixtureCredentials()))
            ->toThrow(SecretsWriteFailed::class, 'could not create parent directory');
    } finally {
        @unlink($secretsDir);
    }
});

// encodePayload() maps json_encode()'s JsonException to SecretsWriteFailed
// without echoing the payload it refused.
it('raises SecretsWriteFailed when the payload cannot be encoded, without leaking it', function (): void {
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);

    $badCredentials = new OpenBankingCredentials(
        applicationId: 'app-fixture-123',
        // A lone continuation byte is not valid UTF-8, so json_encode() fails.
        privateKeyPem: "-----BEGIN PRIVATE KEY-----\n\xB1\xB2\n-----END PRIVATE KEY-----",
        sessionId: null,
        consentExpiresAt: null,
        bankScaHost: null,
        institutionId: null,
    );

    try {
        $repo->save($badCredentials);
        expect(false)->toBeTrue('save() should have refused the un-encodable payload');
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

    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);
    $prevUmask = umask();

    try {
        expect(fn () => $repo->save(openBankingSecretsFixtureCredentials()))
            ->toThrow(SecretsWriteFailed::class, 'could not open temp file');
        expect(umask())->toBe($prevUmask);
    } finally {
        @rmdir($this->obSecretsPath.'.tmp');
    }
});

// The on-disk bytes are shield(encrypt(json)), so strrev() gets back the exact
// Laravel ciphertext — proving the encrypter ran, not just the shield. Legacy
// files hold shield(json) with no encryption and must still load.
it('encrypts the payload at rest so the on-disk bytes decrypt back to the saved JSON, and reads its own encrypted file', function (): void {
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);
    $repo->save(openBankingSecretsFixtureCredentials());

    $ciphertext = strrev((string) file_get_contents($this->obSecretsPath));
    $decryptedJson = $this->obEncrypter->decryptString($ciphertext);

    expect($decryptedJson)->toContain('app-fixture-123')
        ->and($decryptedJson)->toContain('session-fixture-abc')
        ->and($ciphertext)->not->toContain('app-fixture-123')
        ->and($ciphertext)->not->toContain('BEGIN PRIVATE KEY')
        ->and($ciphertext)->not->toContain('session-fixture-abc');

    $loaded = $repo->load();
    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe('app-fixture-123');
    expect($loaded->privateKeyPem)->toBe(openBankingSecretsFixtureCredentials()->privateKeyPem);
    expect($loaded->sessionId)->toBe('session-fixture-abc');
});

it('still reads a legacy plaintext secrets file, then re-persists it encrypted on the next save', function (): void {
    // Pre-encryption on-disk format: shield-protected but NOT encrypted.
    // With the reversing shield that is strrev(json).
    $legacyJson = json_encode([
        'application_id' => 'legacy-app-777',
        'private_key_pem' => "-----BEGIN PRIVATE KEY-----\nlegacy-bytes\n-----END PRIVATE KEY-----",
        'session_id' => 'legacy-session-xyz',
        'consent_expires_at' => '2026-08-01T00:00:00+00:00',
        'bank_sca_host' => 'sca.asnbank.nl',
        'institution_id' => 'ASNBNL21',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    $this->obFiles->put($this->obSecretsPath, strrev($legacyJson));

    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield, $this->obEncrypter);

    $loaded = $repo->load();
    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe('legacy-app-777');
    expect($loaded->privateKeyPem)->toContain('legacy-bytes');
    expect($loaded->sessionId)->toBe('legacy-session-xyz');

    // Saving a legacy file again rewrites it through the APP_KEY layer.
    $repo->save($loaded);
    $upgradedCiphertext = strrev((string) file_get_contents($this->obSecretsPath));
    expect($this->obEncrypter->decryptString($upgradedCiphertext))->toContain('legacy-app-777');
    expect($repo->load()?->applicationId)->toBe('legacy-app-777');
});
