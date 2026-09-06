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
    'forgot_pin' => 'Forgot your PIN? Sign out — if your account password still opens this lock you can sign back in, set a new PIN, and lose nothing. A password reset with a recovery code, or set for you by the account owner, no longer opens it.',

    'errors' => [
        'pin_length' => 'PIN must be at least 6 digits.',

        'too_many_attempts' => 'Too many attempts — try again in :secondss.',
        'incorrect_pin_remaining' => 'Incorrect PIN. :count attempt remaining.|Incorrect PIN. :count attempts remaining.',
        'incorrect_pin' => 'Incorrect PIN.',
    ],
];
