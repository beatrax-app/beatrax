<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Enums;

// Where the pairing ceremony is on screen. The desktop modal runs
// choose_direction -> show_code|enter_code -> confirm -> success, and the phone
// starts at scan instead. Both surfaces keep the value a string on the wire, so
// a step arriving from a client is read back through tryFrom().
enum PairingWizardStep: string
{
    case ChooseDirection = 'choose_direction';

    case Scan = 'scan';

    case ShowCode = 'show_code';

    case EnterCode = 'enter_code';

    case Confirm = 'confirm';

    case Success = 'success';

    // The two arms a reader can start the ceremony on: the camera, or typing
    // the code. A cancelled attempt returns to the arm they chose, so anything
    // else accepted there would walk it forward onto a screen it never reached.
    public function isEntryArm(): bool
    {
        return match ($this) {
            self::Scan, self::EnterCode => true,
            self::ChooseDirection, self::ShowCode, self::Confirm, self::Success => false,
        };
    }
}
