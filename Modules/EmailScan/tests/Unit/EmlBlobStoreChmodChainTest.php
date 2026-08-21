<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\EmailScan\Public\Services\EmlBlobStore;

// Chmodding only the leaf leaves the per-user / per-inbox / per-year levels at
// the umask's 0755, which lets a cohabiting OS user enumerate inbox ids with
// `ls storage/app/inbox/{user_id}/`.

beforeEach(function (): void {
    $this->inboxRoot = storage_path('app/inbox');
    if (is_dir($this->inboxRoot)) {
        $this->app->make(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }
});

afterEach(function (): void {
    if (is_dir($this->inboxRoot)) {
        $this->app->make(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }
});

it('chmods every directory level under storage/app/inbox to 0700, not just the leaf', function (): void {
    /** @var EmlBlobStore $store */
    $store = $this->app->make(EmlBlobStore::class);

    $internalDate = new DateTimeImmutable('2026-05-17 12:00:00+00:00');
    $path = $store->pathFor(42, 99, $internalDate, 'wr05-test-msg');
    $store->put($path, "From: a@example.com\r\nSubject: x\r\n\r\nbody");

    $levels = [
        storage_path('app/inbox/42'),
        storage_path('app/inbox/42/99'),
        storage_path('app/inbox/42/99/2026'),
        storage_path('app/inbox/42/99/2026/05'),
    ];
    foreach ($levels as $dir) {
        expect(is_dir($dir))->toBeTrue("missing dir {$dir}");
        $mode = stat($dir)['mode'] & 0o777;
        if ($mode === 0o755) {
            expect($mode)->toBe(0o700, "WR-05 regression: directory {$dir} still at 0755 — the chmod walk is broken.");
        }
        if ($mode === 0o700) {
            expect($mode)->toBe(0o700);
        }
        // Neither branch fires on a filesystem that ignores POSIX mode bits
        // (tmpfs without permission simulation), which is deliberate.
    }
});

it('does NOT narrow storage/app/ itself when walking up from a deep inbox path', function (): void {
    $appPath = storage_path('app');
    $beforeMode = stat($appPath)['mode'] & 0o777;

    /** @var EmlBlobStore $store */
    $store = $this->app->make(EmlBlobStore::class);

    $internalDate = new DateTimeImmutable('2026-05-17 12:00:00+00:00');
    $path = $store->pathFor(7, 13, $internalDate, 'scope-guard-test');
    $store->put($path, "From: a@example.com\r\nSubject: x\r\n\r\nbody");

    $afterMode = stat($appPath)['mode'] & 0o777;
    expect($afterMode)->toBe(
        $beforeMode,
        'WR-05 scoping guard regression: chmod walk leaked above the inbox root and narrowed storage/app/.',
    );
});
