<?php

declare(strict_types=1);

use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Tests\Support\ShellWithoutAShareSheet;

// On the SM-S928B, "Download as .txt" on /recovery-codes answered *"Beatrax
// asked your device to save the file"* and no sheet ever opened. logcat says
// why, twice over:
//
//   I BridgeJNI: NativePHPCan('Share.File') = 0
//   E BridgeRouter: Function 'Share.File' not found
//
// Share::file() is a void call over nativephp_call(), and an unregistered name
// is swallowed there rather than thrown, so the bridge read its own silence as
// success and the endpoint answered {"saved":true}. The file it left behind is
// 0600 inside the container, unreachable in Files and destroyed by the
// reinstall these codes exist to survive. Recovery codes are shown once; a
// screen that says they are saved when they are not is how an account is lost.

it('reports a failed export on a shell that does not register Share.File', function (): void {
    $bridge = new ShellWithoutAShareSheet;

    $outcome = $bridge->export(
        'beatrax-recovery-codes-and11-walk.txt',
        "VWS6-RXQN-QKKS-S6JF-WCS3\n",
        'Beatrax recovery codes',
        'Keep these somewhere safe.',
    );

    expect($outcome)->toBe(FileExportOutcome::Unsupported)
        ->and($outcome->message())->not->toBe('')
        ->and($bridge->shareWasCalled)->toBeFalse();
});
