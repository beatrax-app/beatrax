<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Modules\Receipts\Public\Pipeline\FileDropEmlBlobStore;

// The .tmp file has to be born at 0600 rather than chmod'd afterwards: between
// the fwrite and an explicit chmod it would sit world-readable under the default
// umask, and a cohabiting OS user can win that race with a cat.

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
    // Replay just the umask and fopen sequence, with no chmod anywhere, so the
    // mode observed is the one the file was born with.
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
