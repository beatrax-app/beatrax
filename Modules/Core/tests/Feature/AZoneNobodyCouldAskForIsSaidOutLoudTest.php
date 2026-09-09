<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Internal\Console\Probes\HostTimezoneProbe;
use Modules\Core\Public\Support\HostTimezone;

uses(RefreshDatabase::class);

// The floor is silent by construction: a host genuinely on UTC and a host that
// could not be asked both answer 'UTC'. Android could be asked by nothing at
// all — no /etc/localtime, no /etc/timezone, no TZ in the process — and read
// its days two hours out from the desktop it synced with for as long as
// nobody went looking. This is what goes looking.

beforeEach(function (): void {
    config()->set('app.timezone_pinned', null);
});

afterEach(function (): void {
    HostTimezone::fake(null);
});

it('warns when nothing could say which zone the machine is in', function (): void {
    HostTimezone::fake('UTC', answered: false);

    $result = app(HostTimezoneProbe::class)->run();

    expect($result->severity)->toBe('warning')
        ->and($result->message)->toContain('could not be asked')
        ->and($result->metadata['source'])->toBe('floor');
});

// The same string, and not the same situation. A host that answers UTC has
// been asked and has told us; nothing is wrong and nothing should be said.
it('stays quiet when the machine answers UTC itself', function (): void {
    HostTimezone::fake('UTC', answered: true);

    $result = app(HostTimezoneProbe::class)->run();

    expect($result->severity)->toBe('ok')
        ->and($result->metadata['source'])->toBe('machine')
        ->and($result->metadata['zone'])->toBe('UTC');
});

it('names the machine when it answers a zone', function (): void {
    HostTimezone::fake('Asia/Tokyo');

    $result = app(HostTimezoneProbe::class)->run();

    expect($result->severity)->toBe('ok')
        ->and($result->metadata['zone'])->toBe('Asia/Tokyo')
        ->and($result->metadata['source'])->toBe('machine');
});

// A pin or a stored choice answers before the machine is ever consulted, so a
// silent floor underneath one of them costs nothing and must not be reported.
it('says nothing about the machine when the environment pinned a zone', function (): void {
    config()->set('app.timezone_pinned', 'Pacific/Auckland');
    HostTimezone::fake('UTC', answered: false);

    $result = app(HostTimezoneProbe::class)->run();

    expect($result->severity)->toBe('ok')
        ->and($result->metadata['source'])->toBe('environment')
        ->and($result->metadata['zone'])->toBe('Pacific/Auckland');
});
