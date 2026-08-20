<?php

declare(strict_types=1);

return [
    'page_title' => 'Import from another device',

    'heading' => 'Import from another device',
    'subtitle' => 'Set up this phone with its own account and lock, then pair it with your other device to pull in your history.',

    'username' => 'Username',
    'password' => 'Password',
    'password_help' => 'At least 12 characters — there is no password reset, only recovery codes.',
    'confirm_password' => 'Confirm password',
    'pin' => 'App-lock PIN',
    'pin_help' => '6-10 digits — unlocks this device.',
    'confirm_pin' => 'Confirm PIN',
    'continue' => 'Continue',

    'failed_heading' => "Setup didn't finish",
    'failed_body' => "Your account was created, but we couldn't finish setting up this device. You can safely try again.",
    'try_again' => 'Try again',

    'recovery_heading' => 'Save these recovery codes',
    'recovery_body' => 'Print these or save them somewhere safe. They will not be shown again.',
    'already_heading' => 'This device is already set up',
    'already_body' => 'Your account exists on this device. Continue to pairing to connect it to your other devices.',
    'recovery_download' => 'Download as .txt',
    'recovery_copy' => 'Copy codes',
    'recovery_copied' => 'Copied',
    'recovery_copy_failed' => 'Could not copy. Write the codes down instead.',
    'recovery_saved' => 'Saved to your downloads.',
    'recovery_confirm' => 'I have saved these codes somewhere safe.',
    'continue_to_pairing' => 'Continue to pairing',

    'errors' => [
        'passwords_mismatch' => 'Passwords do not match.',
        'password_length' => 'Use at least 12 characters.',
        'pin_length' => 'PIN must be at least 6 digits.',
        'pins_mismatch' => "PINs don't match. Try again.",
        'session_expired' => 'Your session expired before setup finished. Please re-enter your PIN and password.',
        'retry_failed' => 'Still could not finish setting up this device. Please try again.',
        'account_failed' => 'Could not create the account.',
    ],
];
