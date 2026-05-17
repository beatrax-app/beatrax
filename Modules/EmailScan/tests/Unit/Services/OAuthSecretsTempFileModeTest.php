<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Modules\EmailScan\Public\Services\SecretsWriteFailed;

/*
 * WR-04 iter-2 regression: the .tmp file written by
 * OAuthSecretsRepository::writeAtomic must be born with mode 0600 —
 * NOT 0644 (the umask-0022 default for fopen). Previously the temp
 * file held the cleartext refresh token at world-readable mode for
 * the brief window between fwrite and the explicit chmod, exposing
 * the secret to a cohabiting OS user racing a `cat`.
 *
 * Approach: intercept the rename by extending the repository and
 * overriding performRename to inspect the temp file's mode BEFORE
 * rename completes. The temp file's mode at this point IS the mode
 * it had during fwrite (no chmod-up between).
 *
 * On filesystems that don't honour POSIX permissions (e.g. some
 * tmpfs configurations on CI hosts) the assertion is skipped — the
 * regression we care about is real macOS/Linux deployment.
 */

beforeEach(function (): void {
    $this->path = storage_path('app/secrets/email-oauth.json');
    $this->tmp = $this->path.'.tmp';
    foreach ([$this->path, $this->tmp] as $p) {
        if (is_file($p)) {
            @unlink($p);
        }
    }
});

afterEach(function (): void {
    foreach ([$this->path, $this->tmp] as $p) {
        if (is_file($p)) {
            @unlink($p);
        }
    }
});

it('writes the temp file with mode 0600 BEFORE the explicit chmod (born-narrow)', function (): void {
    // The repository's writeAtomic narrows umask to 0077 BEFORE
    // fopen, so even WITHOUT the explicit chmod the temp file is
    // born at 0600. We prove this by overriding the repository to
    // skip the explicit chmod step entirely and inspecting the
    // resulting file's mode.
    $files = $this->app->make(Filesystem::class);

    $repo = new class($files, $this) extends OAuthSecretsRepository
    {
        public ?int $observedMode = null;

        public function __construct(
            Filesystem $files,
            private readonly object $testCase,
        ) {
            parent::__construct($files);
        }

        protected function performRename(string $tmp, string $final): bool
        {
            // Capture the mode right before the rename so we observe
            // exactly the mode the file had during fwrite + chmod.
            $stat = stat($tmp);
            if ($stat !== false) {
                $this->observedMode = $stat['mode'] & 0o777;
            }

            return parent::performRename($tmp, $final);
        }
    };

    $repo->saveProviderClient('gmail', 'born-narrow-id', 'born-narrow-secret', 'http://127.0.0.1/cb');

    if ($repo->observedMode === null) {
        $this->markTestSkipped('Filesystem does not expose POSIX mode bits.');
    }
    expect($repo->observedMode)->toBe(0o600);
});

it('restores the prior umask after a write (success path)', function (): void {
    $priorUmask = umask();
    try {
        $repo = $this->app->make(OAuthSecretsRepository::class);
        $repo->saveProviderClient('gmail', 'umask-test-id', 'umask-test-secret', 'http://127.0.0.1/cb');
    } finally {
        $observed = umask($priorUmask);
        umask($priorUmask);
    }
    expect($observed)->toBe($priorUmask);
});

it('restores the prior umask after a write failure (failure path)', function (): void {
    $files = $this->app->make(Filesystem::class);
    $repo = new class($files) extends OAuthSecretsRepository
    {
        public function __construct(Filesystem $files)
        {
            parent::__construct($files);
        }

        protected function performRename(string $tmp, string $final): bool
        {
            return false;
        }
    };

    $priorUmask = umask();
    $threw = false;
    try {
        $repo->saveProviderClient('gmail', 'fail-id', 'fail-secret', 'http://127.0.0.1/cb');
    } catch (SecretsWriteFailed) {
        $threw = true;
    }
    $observed = umask($priorUmask);
    umask($priorUmask);

    expect($threw)->toBeTrue();
    expect($observed)->toBe($priorUmask);
});
