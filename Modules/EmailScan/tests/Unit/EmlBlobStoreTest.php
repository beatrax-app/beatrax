<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\EmailScan\Public\Services\EmlBlobStore;

function ebsStore(): EmlBlobStore
{
    return new EmlBlobStore(new Filesystem, new UserDataPathService);
}

it('accepts the short hex shape Gmail returns', function (): void {
    $store = ebsStore();
    $path = $store->pathFor(1, 2, new DateTimeImmutable('2026-05-17T12:00:00Z'), '18f9b4a2c1e5d6f7');
    expect($path)->toContain('app/inbox/1/2/2026/05/');
    expect($path)->toEndWith('.eml');
});

it('accepts realistic Microsoft Graph immutable ids with base64 padding and slashes', function (): void {
    $store = ebsStore();
    // ImmutableId shape: long base64 with `=` padding and slashes.
    $graphId = 'AAMkADYwYTYwOWY3LWQxMjEtNDNiYi05ZWI4LTM1OTcxZTllZGMwOQBGAAAAAACGiYUxK2KCT_lvL6dQ4d5XBwBOptVwhUONRpW8AAA=';
    $path = $store->pathFor(1, 2, new DateTimeImmutable('2026-05-17T12:00:00Z'), $graphId);
    expect($path)->toContain('app/inbox/1/2/2026/05/');
    expect($path)->toEndWith('.eml');
});

it('accepts Graph ids up to 512 characters', function (): void {
    $store = ebsStore();
    $longId = str_repeat('A', 512);
    $path = $store->pathFor(1, 2, new DateTimeImmutable('2026-05-17T12:00:00Z'), $longId);
    expect($path)->toEndWith('.eml');
});

it('rejects ids over 512 characters', function (): void {
    $store = ebsStore();
    $tooLong = str_repeat('A', 513);
    expect(fn () => $store->pathFor(1, 2, new DateTimeImmutable('2026-05-17T12:00:00Z'), $tooLong))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects ids with path-traversal characters', function (): void {
    $store = ebsStore();
    expect(fn () => $store->pathFor(1, 2, new DateTimeImmutable('2026-05-17T12:00:00Z'), '../etc/passwd'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects ids containing a null byte', function (): void {
    $store = ebsStore();
    expect(fn () => $store->pathFor(1, 2, new DateTimeImmutable('2026-05-17T12:00:00Z'), "abc\0def"))
        ->toThrow(InvalidArgumentException::class);
});

it('two distinct ids never produce the same on-disk slug (collision guard)', function (): void {
    $store = ebsStore();
    $date = new DateTimeImmutable('2026-05-17T12:00:00Z');
    $a = $store->pathFor(1, 2, $date, 'AAMkADYwYTYwOWY3LWQxMjEtNDNiYi05ZWI4LTM1OTcxZTllZGMwOQAAA=');
    $b = $store->pathFor(1, 2, $date, 'AAMkADYwYTYwOWY3LWQxMjEtNDNiYi05ZWI4LTM1OTcxZTllZGMwOQAAB=');
    expect($a)->not->toBe($b);
});

it('case-only variations resolve to distinct paths even on case-insensitive FS', function (): void {
    // Hashing, not the raw id, is what stops a case-insensitive filesystem
    // silently collapsing two ids onto the same .eml.
    $store = ebsStore();
    $date = new DateTimeImmutable('2026-05-17T12:00:00Z');
    $lower = $store->pathFor(1, 2, $date, 'abcdef123');
    $upper = $store->pathFor(1, 2, $date, 'ABCDEF123');
    $lowerBasename = basename($lower);
    $upperBasename = basename($upper);
    expect(substr($lowerBasename, 0, 32))->not->toBe(substr($upperBasename, 0, 32));
});
