<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Modules\Desktop\Internal\Listeners\HandleNativeOpenFile;
use Modules\Desktop\Public\Events\FileOpenedFromOs;
use Native\Desktop\Events\App\OpenFile;

beforeEach(function (): void {
    $this->fixturesDir = storage_path('app/test-handle-open-file-'.bin2hex(random_bytes(4)));
    mkdir($this->fixturesDir, 0700, true);
});

afterEach(function (): void {
    if (is_dir($this->fixturesDir)) {
        foreach (glob($this->fixturesDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->fixturesDir);
    }
});

// The `OpenFile::$path` payload is untyped and its shape drifts between
// NativePHP versions, so every documented shape is pinned here.
it('passes a plain-string payload through to the intake verbatim', function (): void {
    Event::fake([FileOpenedFromOs::class]);

    $path = $this->fixturesDir.'/bank.csv';
    file_put_contents($path, "date,amount\n2026-01-01,12.34");

    /** @var HandleNativeOpenFile $listener */
    $listener = app(HandleNativeOpenFile::class);
    $listener->handle(new OpenFile($path));

    Event::assertDispatched(
        FileOpenedFromOs::class,
        fn (FileOpenedFromOs $event): bool => $event->path === realpath($path),
    );
})->group('phase-15');

it('prefers the array `path` key over sibling string values (WR-03 regression guard)', function (): void {
    // An associative payload used to have the iteration fallback pick
    // 'open-file' — insertion order, first non-empty string — silently
    // dropping the legitimate file.
    Event::fake([FileOpenedFromOs::class]);

    $path = $this->fixturesDir.'/keyed.eml';
    file_put_contents($path, "From: a@b\nSubject: t\n\nhello");

    /** @var HandleNativeOpenFile $listener */
    $listener = app(HandleNativeOpenFile::class);
    $listener->handle(new OpenFile([
        'type' => 'open-file',
        'path' => $path,
        'timestamp' => '2026-05-23T12:00:00Z',
    ]));

    Event::assertDispatched(
        FileOpenedFromOs::class,
        fn (FileOpenedFromOs $event): bool => $event->path === realpath($path),
    );
})->group('phase-15');

it('falls back to list iteration for a single-element list payload', function (): void {
    // Older NativePHP versions emit a list-shaped payload, which the fallback
    // iteration handles without picking up named fields' string values.
    Event::fake([FileOpenedFromOs::class]);

    $path = $this->fixturesDir.'/list.csv';
    file_put_contents($path, "x,y\n1,2");

    /** @var HandleNativeOpenFile $listener */
    $listener = app(HandleNativeOpenFile::class);
    $listener->handle(new OpenFile([$path]));

    Event::assertDispatched(
        FileOpenedFromOs::class,
        fn (FileOpenedFromOs $event): bool => $event->path === realpath($path),
    );
})->group('phase-15');

it('does not dispatch when the payload is an associative array without a `path` key (only metadata strings)', function (): void {
    Event::fake([FileOpenedFromOs::class]);

    /** @var HandleNativeOpenFile $listener */
    $listener = app(HandleNativeOpenFile::class);
    $listener->handle(new OpenFile([
        'type' => 'open-file',
        'timestamp' => '2026-05-23T12:00:00Z',
    ]));

    Event::assertNotDispatched(FileOpenedFromOs::class);
})->group('phase-15');

it('does not dispatch when the `path` key is present but empty', function (): void {
    Event::fake([FileOpenedFromOs::class]);

    /** @var HandleNativeOpenFile $listener */
    $listener = app(HandleNativeOpenFile::class);
    $listener->handle(new OpenFile(['path' => '']));

    Event::assertNotDispatched(FileOpenedFromOs::class);
})->group('phase-15');

it('does not dispatch on a non-string, non-array payload', function (): void {
    Event::fake([FileOpenedFromOs::class]);

    /** @var HandleNativeOpenFile $listener */
    $listener = app(HandleNativeOpenFile::class);
    $listener->handle(new OpenFile(null));

    Event::assertNotDispatched(FileOpenedFromOs::class);
})->group('phase-15');
