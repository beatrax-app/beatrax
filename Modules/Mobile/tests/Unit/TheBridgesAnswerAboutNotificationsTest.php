<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Notifications\BridgeNotificationSwitch;

// Three answers, not two. The platform can say yes, it can say no, and the
// bridge can hand back something that settles nothing — an error envelope, an
// empty string, a value the C extension did not make a string at all. Reading
// any of those as "no" would tell a reader their device is dropping
// notifications it is showing, on the strength of a call that failed.

it('reads the platform saying yes and the platform saying no', function (bool $granted): void {
    expect(BridgeNotificationSwitch::readAnswer(json_encode(['granted' => $granted])))->toBe($granted);
})->with([true, false]);

it('settles nothing on an answer that settles nothing', function (mixed $answer): void {
    expect(BridgeNotificationSwitch::readAnswer($answer))->toBeNull();
})->with([
    'the shell refused the call' => ['{"status":"error","code":"NOT_FOUND","message":"Function not found"}'],
    'a success envelope with no verdict' => ['{"success":true}'],
    'granted as a string, which is not an answer' => ['{"granted":"true"}'],
    'granted as null' => ['{"granted":null}'],
    'an empty answer' => [''],
    'not JSON at all' => ['LocalNotification.CheckPermission'],
    'a JSON scalar rather than an object' => ['true'],
    'nothing at all' => [null],
    'not a string' => [false],
]);

// The name is what the manifest registers and what nativephp_can() is asked
// about; a rename on either side is a call that answers nothing forever.
it('asks for the function the plugin manifest registers', function (): void {
    expect(BridgeNotificationSwitch::FUNCTION)->toBe('LocalNotification.CheckPermission');
});

// Off a device there is no bridge, so the switch has nothing to report and
// must not invent a refusal out of its own absence.
it('reports nothing off a device', function (): void {
    expect((new BridgeNotificationSwitch)->enabled())->toBeNull();
});
