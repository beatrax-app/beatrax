<?php

declare(strict_types=1);

use Modules\Mobile\Tests\Support\BridgeAnswerStubVault;
use Modules\Mobile\Tests\Support\RecordingMobileLogger;

// Between the bridge call and the capability sits a reading, and until this
// file every fake replaced the reading rather than the call: the repo root does
// not autoload the plugin, so the only cases that reached it were skipped here
// and ran in the mobile-app root alone. What the device says arrives as decoded
// JSON, so "no key", "a key holding the wrong type" and "a truthy word that is
// not true" are shapes the wire really produces, not defensive padding.

it('reads a device that says it can hold the key', function (): void {
    $vault = new BridgeAnswerStubVault(['available' => true, 'reason' => 'available']);

    expect($vault->isAvailable())->toBeTrue();
});

it('reads a device that says it cannot, and carries the reason it gave', function (): void {
    $log = new RecordingMobileLogger;
    $vault = new BridgeAnswerStubVault(['available' => false, 'reason' => 'none_enrolled'], $log);

    expect($vault->isAvailable())->toBeFalse()
        ->and($log->records[0]['context']['reason'])->toBe('none_enrolled');
});

// `available` is compared to true rather than read as a boolean, so a decoder
// that hands back the string "true" — or a key that is missing altogether —
// cannot open the vault by being merely truthy.
it('refuses an availability that is not the boolean true', function (mixed $available): void {
    $vault = new BridgeAnswerStubVault(['available' => $available, 'reason' => 'available']);

    expect($vault->isAvailable())->toBeFalse();
})->with([
    'the string "true"' => ['true'],
    'the number 1' => [1],
    'null' => [null],
]);

// Every one of these is the same verdict — unreadable — and each arrives by a
// different road, which is why they are named apart rather than counted.
it('calls an answer it cannot read unreadable', function (mixed $answer): void {
    $log = new RecordingMobileLogger;
    $vault = new BridgeAnswerStubVault($answer, $log);

    expect($vault->isAvailable())->toBeFalse()
        ->and($log->records[0]['context']['reason'])->toBe('unreadable');
})->with([
    'a root with no plugin, where the call is never made' => [null],
    'the bridge refusing with a message instead of an object' => ['function not found'],
    'an object with neither key in it' => [[]],
    'a refusal naming no reason' => [['available' => false]],
    'a reason that is not a string' => [['available' => false, 'reason' => 7]],
    'a reason that is the empty string' => [['available' => false, 'reason' => '']],
]);
