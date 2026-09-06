<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'This version of Beatrax has nowhere to store an unlock key, so biometric unlock is not offered. Your device is not the limitation.',
    'error_enroll_unprotected' => 'Biometric unlock needs an operating-system key store, and this installation has none. Enrolling would leave the unlock key readable beside your data, so it is not offered here.',
    'error_enroll_locked' => 'Unlock the app before enrolling.',
    'error_enroll_failed' => 'Your device declined to store the key. Biometric unlock is unavailable.',
    'heading' => 'App lock',

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
    'biometric_unavailable' => 'This version of Beatrax cannot offer biometric unlock. Your PIN is the only unlock here.',

    'deenroll_modal_heading' => 'Remove biometric unlock — confirm with PIN',
    'current_pin_label' => 'Current PIN',
    'remove_biometric' => 'Remove biometric',
    'keep_biometric' => 'Keep biometric',

    'auto_lock' => 'Auto-lock after',
    'auto_lock_note' => 'Beatrax locks after this long without activity, and sooner if you leave it: switching to another app, or hiding or closing the window, locks Beatrax within :window whatever this setting says.',
    'idle_1' => '1 minute',
    'idle_5' => '5 minutes',
    'idle_15' => '15 minutes',
    'idle_30' => '30 minutes',

    'disable_modal_heading' => 'Disable app lock — confirm with PIN',
    'disable_lock' => 'Disable lock',
    'keep_lock' => 'Keep app lock',

    'forgot_modal_heading' => 'Reset PIN — confirm with account password',
    'forgot_modal_body' => 'Your account password recovers the lock key, so resetting the PIN loses no data — as long as that password still opens the lock. A password reset with a recovery code, or one set for you by the account owner, does not.',
    'confirm_new_pin_label' => 'Confirm new PIN',
    'reset_pin' => 'Reset PIN',
    'cancel' => 'Cancel',

    'change_modal_heading' => 'Change PIN — confirm with current PIN',
    'keep_pin' => 'Keep PIN',

    'error_pin_too_short' => 'PIN must be at least 6 digits.',
    'error_pin_digits' => 'PIN must be :min to :max digits — numbers only.',
    'error_pin_mismatch' => 'PINs don\'t match. Try again.',
    'error_pin_required' => 'Enter your PIN.',
    'error_pin_incorrect' => 'Incorrect PIN.',
    'error_account_password_required' => 'Enter your account password.',
    'error_account_password' => 'Incorrect account password.',
    'change_pin_success' => 'Your encryption key has been re-secured with your new PIN.',
    'error_forgot_failed' => 'PIN reset failed — the recovery key is unavailable.',
    'error_enable_first' => 'Enable the PIN lock first before enrolling biometrics.',
    'error_disable_blocked_by_encryption' => 'Your notes and counterparty details are encrypted with the key this app lock holds, so turning the lock off would leave them unreadable. The lock stays on — change your PIN instead.',
    'error_key_material_lost' => 'This device no longer holds the key that opens your encrypted data, so a new PIN cannot make it readable again. Restore an encrypted backup made while the key still worked — this device cannot pair its way back, because pairing needs the app lock that key opens.',
    'error_recovery_wrap_stale' => 'Your account password no longer opens this app lock — it was changed after the lock was set up. Your PIN still works, but there is nothing behind it if you forget it. Re-link your account password now.',
    'relink_recovery' => 'Re-link account password',
    'relink_modal_heading' => 'Re-link account password — confirm with PIN',
    'relink_recovery_success' => 'Your account password can recover this app lock again.',
];
