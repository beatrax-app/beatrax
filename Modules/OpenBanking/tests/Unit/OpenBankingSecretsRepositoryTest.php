<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Exceptions\OpenBankingCredentialsException;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Public\Services\SecretsWriteFailed;

/*
 * Task 1 (19-03): OpenBankingSecretsRepository — file-backed, atomic,
 * chmod-600, SecretShield-wrapped credential store (D-05/06). Verbatim
 * port of the git-recovered pre-Phase-12 OAuthSecretsRepository
 * atomic-write primitive, layered with the SecretShield seam.
 *
 * The real bound SecretShield is PassthroughSecretShield (identity by
 * design on web/desktop-without-keychain) — asserting "not
 * plaintext-readable" against that binding would be tautologically
 * false. Instead every test here injects OpenBankingReversingShield, a
 * deterministic NON-identity double, to prove the repository actually
 * routes bytes through protect()/reveal() rather than writing the raw
 * JSON straight to disk.
 */

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
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield);
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
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield);
    $repo->save(openBankingSecretsFixtureCredentials());

    expect(is_file($this->obSecretsPath))->toBeTrue();
    expect(fileperms($this->obSecretsPath) & 0o777)->toBe(0o600);
    expect(fileperms(dirname($this->obSecretsPath)) & 0o777)->toBe(0o700);
});

it('shields the on-disk bytes so raw credential JSON is never plaintext-readable', function (): void {
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield);
    $repo->save(openBankingSecretsFixtureCredentials());

    $onDisk = (string) file_get_contents($this->obSecretsPath);
    expect($onDisk)->not->toContain('app-fixture-123');
    expect($onDisk)->not->toContain('BEGIN PRIVATE KEY');
    expect($onDisk)->not->toContain('sca.asnbank.nl');
    expect($onDisk)->not->toContain('application_id');
});

it('raises SecretsWriteFailed with no credential material on a simulated rename failure, cleans up the temp file, and restores umask', function (): void {
    $repo = new class(new Filesystem, new OpenBankingReversingShield) extends OpenBankingSecretsRepository
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
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield);
    expect($repo->load())->toBeNull();
});

it('reports hasApplication false before save and true after', function (): void {
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield);
    expect($repo->hasApplication())->toBeFalse();

    $repo->save(openBankingSecretsFixtureCredentials());
    expect($repo->hasApplication())->toBeTrue();
});

it('clear removes the credential file, load returns null afterward, and clear is idempotent', function (): void {
    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield);
    $repo->save(openBankingSecretsFixtureCredentials());
    expect($repo->load())->not->toBeNull();

    $repo->clear();
    expect($repo->load())->toBeNull();
    expect(is_file($this->obSecretsPath))->toBeFalse();

    // Idempotent — clearing an already-missing file does not throw.
    $repo->clear();
});

/*
 * What readAll() does with a file it cannot make sense of.
 *
 * The reveal happens before the decode, because on the desktop bundle the
 * on-disk bytes are safeStorage ciphertext rather than JSON. That ordering is
 * why a corrupt file cannot simply be detected by looking at it — every case
 * below writes bytes that reveal to something the decoder then has to judge.
 */

it('treats an empty secrets file as no credentials rather than a corrupt one', function (): void {
    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    $this->obFiles->put($this->obSecretsPath, '');

    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield);

    expect($repo->load())->toBeNull()
        ->and($repo->hasApplication())->toBeFalse();
});

// A file that will not decode is a different thing from a file with nothing
// in it, and the difference matters: one means the wizard was never run, the
// other means something on disk needs repairing.
it('refuses a secrets file whose revealed bytes are not JSON', function (): void {
    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    $this->obFiles->put($this->obSecretsPath, strrev('{"applicationId": '));

    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield);

    expect(fn () => $repo->load())
        ->toThrow(OpenBankingCredentialsException::class, $this->obSecretsPath);
});

// The message may name the path and nothing else — the revealed payload and
// the raw bytes are both credential material, and this exception is logged
// wherever it surfaces.
it('names only the path when it refuses an unreadable secrets file', function (): void {
    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    $this->obFiles->put($this->obSecretsPath, strrev('{"applicationId": "super-secret-value"'));

    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield);

    try {
        $repo->load();
        expect(false)->toBeTrue('load() should have refused the file');
    } catch (OpenBankingCredentialsException $e) {
        expect($e->getMessage())->toContain($this->obSecretsPath)
            ->and($e->getMessage())->not->toContain('super-secret-value');
    }
});

// Valid JSON that is not an object carries no fields to read. Treated as an
// empty store rather than an error: there is nothing to repair, there is
// simply nothing there.
it('reads a well-formed non-object secrets file as an empty store', function (string $revealed): void {
    $this->obFiles->ensureDirectoryExists(dirname($this->obSecretsPath));
    $this->obFiles->put($this->obSecretsPath, strrev($revealed));

    $repo = new OpenBankingSecretsRepository(new Filesystem, new OpenBankingReversingShield);

    expect($repo->load())->toBeNull();
})->with([
    'a string' => ['"just a string"'],
    'a number' => ['42'],
    'null' => ['null'],
]);
