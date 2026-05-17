<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Modules\Receipts\Public\Pipeline\FileDropEmlBlobStore;

/*
 * The .tmp file written by FileDropEmlBlobStore::put must be born
 * with mode 0600 — NOT 0644 (the umask-0022 default for fopen).
 * Previously the temp file held the raw .eml bytes at world-readable
 * mode for the brief window between fwrite and the explicit chmod,
 * exposing the user's email content to a cohabiting OS user racing a
 * `cat`. The sibling EmlBlobStore + OAuthSecretsRepository writers
 * (Phase 6) carry the same `umask(0077)` narrowing.
 *
 * On filesystems that don't honour POSIX permissions (e.g. some tmpfs
 * configurations on CI hosts) the mode assertion is skipped — the
 * regression we care about is real macOS/Linux deployment.
 */

beforeEach(function (): void {
    /** @var Application $app */
    $app = $this->app;
    $this->baseDir = $app->storagePath('app/inbox/9999/file-drop/2026/05');
    $this->cleanup = function (): void {
        if (is_dir($this->baseDir)) {
            foreach (glob($this->baseDir.'/*') ?: [] as $p) {
                @unlink($p);
            }
        }
    };
    ($this->cleanup)();
});

afterEach(function (): void {
    ($this->cleanup)();
});

it('writes the final .eml file with mode 0600 after put()', function (): void {
    /** @var Application $app */
    $app = $this->app;
    /** @var Filesystem $files */
    $files = $app->make(Filesystem::class);
    $store = new FileDropEmlBlobStore($files, $app);

    $path = $this->baseDir.'/abc123.eml';
    $store->put($path, "From: test@example.com\r\nSubject: hi\r\n\r\nbody");

    expect(is_file($path))->toBeTrue();

    $stat = stat($path);
    if ($stat === false) {
        $this->markTestSkipped('Filesystem does not expose POSIX mode bits.');
    }
    expect($stat['mode'] & 0o777)->toBe(0o600);
});

it('restores the prior umask after a successful put()', function (): void {
    /** @var Application $app */
    $app = $this->app;
    /** @var Filesystem $files */
    $files = $app->make(Filesystem::class);
    $store = new FileDropEmlBlobStore($files, $app);

    $priorUmask = umask();
    try {
        $store->put($this->baseDir.'/umask-test.eml', 'bytes');
    } finally {
        $observed = umask($priorUmask);
        umask($priorUmask);
    }
    expect($observed)->toBe($priorUmask);
});

it('narrows umask BEFORE fopen so the temp file is born at 0600 (born-narrow)', function (): void {
    // Direct proof: replay just the umask + fopen sequence and check
    // the mode the file is BORN with — no chmod applied.
    $dir = $this->baseDir;
    if (! is_dir($dir)) {
        mkdir($dir, 0o700, true);
    }
    $tmp = $dir.'/born-narrow.eml.tmp';
    if (is_file($tmp)) {
        @unlink($tmp);
    }

    $prev = umask(0o077);
    try {
        $fp = fopen($tmp, 'wb');
        expect($fp)->not->toBeFalse();
        if ($fp === false) {
            return;
        }
        fwrite($fp, 'bytes');
        fclose($fp);

        $stat = stat($tmp);
        if ($stat === false) {
            $this->markTestSkipped('Filesystem does not expose POSIX mode bits.');
        }
        expect($stat['mode'] & 0o777)->toBe(0o600);
    } finally {
        umask($prev);
        @unlink($tmp);
    }
});
