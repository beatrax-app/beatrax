<?php

declare(strict_types=1);

return [
    'heading' => 'Biometric unlock',
    'unavailable' => 'Biometric unlock is only available in the beatrax mobile app.',
    'toggle_label' => 'Unlock with Face ID / Touch ID',
    'toggle_help' => 'Unlock the app after a restart with biometrics instead of your PIN. Your PIN is still required periodically and whenever your biometrics change.',
    'toggle_aria' => 'Unlock with biometrics',
    'confirm_pin_heading' => 'Confirm your PIN to enable',
    'current_pin' => 'Current PIN',
    'enable' => 'Enable biometric unlock',

    'errors' => [
        'unavailable' => 'Biometric unlock is not available on this device.',
        'pin_required' => 'Enter your PIN (4–10 digits) to enable biometric unlock.',
        'enroll_failed' => 'Could not enable biometric unlock — check your PIN and try again.',
    ],
];
