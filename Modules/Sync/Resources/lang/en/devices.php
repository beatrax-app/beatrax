<?php

declare(strict_types=1);

return [
    'heading' => 'Devices & Sync',

    'enable_sync' => 'Enable sync',
    'enable_sync_help' => 'Share your data securely across trusted devices. Requires an app lock.',

    'app_lock_notice' => 'Set an app lock first to enable sync.',
    'go_to_app_lock' => 'Go to App lock',

    'encrypted_at_rest' => 'Data encrypted at rest',
    'encrypted_at_rest_scope' => 'Notes, transaction descriptions and the names and IBANs of who you pay are encrypted with your app-lock passphrase. Amounts, dates and your own account name and IBAN are not, and some merchant names still appear in plain text elsewhere in the database file.',
    'on' => 'On',
    'securing' => 'Securing your data…',
    'do_not_close' => 'Do not close this window.',
    'encryption_progress_aria' => 'Encryption progress',
    'not_encrypted_offer' => 'Your data is not encrypted at rest. Set up encryption to protect it if this device is lost or stolen.',
    'enable_encryption' => 'Enable encryption',

    'your_devices' => 'Your devices',

    // Settings keeps a pointer to the moved surface; the section
    // itself now lives on /sync with the status and sync action.
    'moved_help' => 'Pairing, device names and encryption now live with your sync status.',
    'moved_cta' => 'Open Sync & Device',
    'device_name' => 'Device name',
    'save' => 'Save',
    'peer_default_name' => 'Paired device',
    'rename_device' => 'Rename device',
    'this_device' => 'This device',
    'removed' => 'Removed',
    'confirmed' => 'Confirmed',
    'awaiting_confirmation' => 'Awaiting confirmation',
    'safety_number_words' => 'Safety number words:',
    'paired' => 'Paired',
    'remove_aria' => 'Remove :name',
    'remove' => 'Remove',
    'pair_new_device' => 'Pair a new device',

    'relay_endpoint' => 'Relay endpoint',
    'relay_endpoint_help' => 'Optional. When set, offline devices sync via this relay. Leave empty for LAN&#8209;direct only.',
    'relay_endpoint_aria' => 'Relay endpoint URL',
    'relay_insecure_warning' => 'This relay endpoint uses plain HTTP. While the relay never decrypts your data, an insecure connection exposes encrypted sizes and timing to network observers. Use an <strong>https://</strong> endpoint for best privacy.',

    'enable_at_rest' => 'Enable at-rest encryption',
    'enable_at_rest_body' => 'Your data will be encrypted using your app-lock passphrase. A pre-migration backup will be created automatically.',
    'no_recovery_warning' => 'If you lose your app-lock passphrase and have no backup or other trusted device, your data cannot be recovered.',
    'recover_help' => 'To recover access, re-pair this device from another trusted device, or use your independent encrypted backup.',
    'amounts_plaintext' => 'Amounts are not encrypted at rest — balances and totals stay readable so your monthly totals keep adding up correctly.',
    'search_plaintext' => 'The search index keeps a plaintext copy of merchant and description text so full-text search keeps working.',
    'keep_unencrypted' => 'Keep data unencrypted',
    'encryption_enabled' => 'Encryption enabled',
    'encryption_enabled_body' => 'Your data is now encrypted at rest.',
    'done_encryption_enabled' => 'Done — encryption enabled',
    'encryption_failed' => 'Encryption setup failed',
    'encryption_failed_body' => 'Your data was not changed. Your backup was preserved.',
    'close_no_changes' => 'Close — no changes made',

    'remove_this_device' => 'Remove this device',
    'removing' => 'Removing:',
    'remove_rotates_key' => 'Removing this device rotates the encryption key so it receives no future updates.',
    'remove_cannot_erase' => 'It cannot erase data already on that device. If this device was lost or stolen, treat any data it held as exposed.',
    'remove_device' => 'Remove device',
    'keep_device' => 'Keep device',
    'rotating_key' => 'Rotating encryption key…',

    'flash' => [
        'app_lock_first' => 'Set an app lock first to enable sync.',
        'enable_failed' => 'Failed to enable sync. Make sure your app lock is active and try again.',
        'cannot_remove_self' => 'You cannot remove this device — it is the one you are using.',
        'remove_failed' => 'Failed to remove device. Please try again.',
        'app_lock_first_settings' => 'Set an app lock first to change sync settings.',
        'relay_cleared' => 'Relay endpoint cleared.',
        'relay_saved' => 'Relay endpoint saved.',
        'relay_save_failed' => 'Failed to save relay endpoint: :message',
    ],
];
