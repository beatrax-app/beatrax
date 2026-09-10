<?php

declare(strict_types=1);

return [
    'page_title' => 'Unlock',

    'digits_entered' => ':count digit entered|:count digits entered',
    'pin_pad' => 'PIN pad',
    'digit' => 'Digit :digit',
    'backspace' => 'Backspace',
    'ok' => 'OK',
    'ok_aria' => 'OK — confirm PIN',
    'sign_out' => 'Sign out',
    'forgot_pin' => 'Forgot your PIN? Sign out',

    'errors' => [
        'pin_length' => 'PIN must be at least 6 digits.',

        'too_many_attempts' => 'Too many attempts — try again in :secondss.',
        'incorrect_pin_remaining' => 'Incorrect PIN. :count attempt remaining.|Incorrect PIN. :count attempts remaining.',
        'incorrect_pin' => 'Incorrect PIN.',

        'pin_changed' => 'The PIN for this device was changed while you were unlocking. Enter the current PIN.',

        'biometric_reset' => 'Biometric unlock was reset. Enter your PIN, then turn it back on under App lock.',
    ],
];
