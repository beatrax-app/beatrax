<?php

declare(strict_types=1);

use Modules\EmailScan\Public\Dto\ScanCursor;

it('gmail() returns a gmail-provider cursor with historyId set', function (): void {
    $cursor = ScanCursor::gmail('12345');
    expect($cursor->provider)->toBe('gmail');
    expect($cursor->historyId)->toBe('12345');
    expect($cursor->deltaLink)->toBeNull();
    expect($cursor->isEmpty())->toBeFalse();
});

it('microsoft() returns a microsoft-provider cursor with deltaLink set', function (): void {
    $url = 'https://graph.microsoft.com/v1.0/me/messages/delta?$skiptoken=abc';
    $cursor = ScanCursor::microsoft($url);
    expect($cursor->provider)->toBe('microsoft');
    expect($cursor->deltaLink)->toBe($url);
    expect($cursor->historyId)->toBeNull();
    expect($cursor->isEmpty())->toBeFalse();
});

it('emptyFor(gmail) returns an empty cursor', function (): void {
    $cursor = ScanCursor::emptyFor('gmail');
    expect($cursor->provider)->toBe('gmail');
    expect($cursor->historyId)->toBeNull();
    expect($cursor->deltaLink)->toBeNull();
    expect($cursor->isEmpty())->toBeTrue();
});

it('emptyFor(microsoft) returns an empty cursor', function (): void {
    $cursor = ScanCursor::emptyFor('microsoft');
    expect($cursor->provider)->toBe('microsoft');
    expect($cursor->isEmpty())->toBeTrue();
});

it('rejects an unknown provider on direct construction', function (): void {
    expect(fn () => new ScanCursor('yahoo', null, null))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an unknown provider via emptyFor()', function (): void {
    expect(fn () => ScanCursor::emptyFor('yahoo'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an empty Gmail historyId', function (): void {
    expect(fn () => ScanCursor::gmail(''))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a non-Graph deltaLink URL', function (): void {
    expect(fn () => ScanCursor::microsoft('https://wrong.example.com/delta'))
        ->toThrow(InvalidArgumentException::class);
});

it('round-trips through Spatie Data toArray + ::from for gmail', function (): void {
    $cursor = ScanCursor::gmail('54321');
    $array = $cursor->toArray();
    $rebuilt = ScanCursor::from($array);
    expect($rebuilt->provider)->toBe($cursor->provider);
    expect($rebuilt->historyId)->toBe($cursor->historyId);
    expect($rebuilt->deltaLink)->toBe($cursor->deltaLink);
});

it('round-trips through Spatie Data toArray + ::from for microsoft', function (): void {
    $url = 'https://graph.microsoft.com/v1.0/me/messages/delta?$skiptoken=xyz';
    $cursor = ScanCursor::microsoft($url);
    $array = $cursor->toArray();
    $rebuilt = ScanCursor::from($array);
    expect($rebuilt->provider)->toBe('microsoft');
    expect($rebuilt->deltaLink)->toBe($url);
    expect($rebuilt->historyId)->toBeNull();
});
