<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Biometric unlock is not available on this device.',
    'error_enroll_locked' => 'Unlock the app before enrolling.',
    'error_enroll_failed' => 'Your device declined to store the key. Biometric unlock is unavailable.',
    'heading' => 'App lock',

    // Settings keeps a pointer only; the controls themselves live on the
    // sync surface at /sync#app-lock.
    'moved_help' => "Your PIN, auto-lock timing and biometric unlock live with this device's sync settings.",
    'moved_cta' => 'Open Sync & Device',

    'toggle_label' => 'Lock app with PIN',
    'toggle_description' => 'Replaces daily sign-in with a PIN. Sessions stay active for 30 days.',

    'setup_heading' => 'Set a PIN to enable lock',
    'new_pin_label' => 'New PIN (6–10 digits)',
    'confirm_pin_label' => 'Confirm PIN',
    'account_password_label' => 'Account password',
    'account_password_note' => '(required to create a recovery key)',
    'account_password_placeholder' => 'Your account password',
    'set_pin' => 'Set PIN',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Change your current PIN.',
    'change_pin' => 'Change PIN',
    'forgot_pin_link' => 'Forgot your PIN? Reset it with your account password.',

    'biometric_enrolled_description' => 'This device is enrolled for biometric unlock.',
    'biometric_enroll_description' => 'Enroll this device to unlock with biometrics.',
    'remove' => 'Remove',
    'enroll' => 'Enroll',
    'biometric_unavailable' => 'Biometric unlock is not available on this device.',

    'deenroll_modal_heading' => 'Remove biometric unlock — confirm with PIN',
    'current_pin_label' => 'Current PIN',
    'remove_biometric' => 'Remove biometric',
    'keep_biometric' => 'Keep biometric',

    'auto_lock' => 'Auto-lock after',
    'idle_1' => '1 minute',
    'idle_5' => '5 minutes',
    'idle_15' => '15 minutes',
    'idle_30' => '30 minutes',

    'disable_modal_heading' => 'Disable app lock — confirm with PIN',
    'disable_lock' => 'Disable lock',
    'keep_lock' => 'Keep app lock',

    'forgot_modal_heading' => 'Reset PIN — confirm with account password',
    'forgot_modal_body' => 'Your account password recovers the lock key, so resetting the PIN never loses data.',
    'confirm_new_pin_label' => 'Confirm new PIN',
    'reset_pin' => 'Reset PIN',
    'cancel' => 'Cancel',

    'change_modal_heading' => 'Change PIN — confirm with current PIN',
    'keep_pin' => 'Keep PIN',

    'error_pin_too_short' => 'PIN must be at least 6 digits.',
    'error_pin_mismatch' => 'PINs don\'t match. Try again.',
    'error_pin_incorrect' => 'Incorrect PIN.',
    'error_account_password' => 'Incorrect account password.',
    'change_pin_success' => 'Your encryption key has been re-secured with your new PIN.',
    'error_forgot_failed' => 'PIN reset failed — the recovery key is unavailable.',
    'error_enable_first' => 'Enable the PIN lock first before enrolling biometrics.',
];
