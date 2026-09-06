<?php

declare(strict_types=1);

return [
    'banner_aria' => 'System alerts',

    'actions' => [
        'download_and_install' => 'Download and install',
        'download_and_install_aria' => 'Download and install — marks system alert #:id as resolved',
        'skip_version' => 'Skip this version',
        'release_notes' => 'Release notes →',
        'update_now' => 'Update now',
        'update_now_aria' => 'Update now — marks system alert #:id as resolved',
        'remind_later' => 'Remind me later',
        'mark_resolved' => 'Mark as resolved',
        'mark_resolved_aria' => 'Mark as resolved — system alert #:id',
        'assign_in_budgets' => 'Assign in Budgets',
        'dismiss' => 'Dismiss',
        'dismiss_aria' => 'Dismiss — system alert #:id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'budget alerts',
        'daily-triggers' => 'daily reminders and digest',
    ],

    'messages' => [
        'update_available' => 'Update available — Beatrax :version. Nothing is downloaded until you choose to install; Beatrax then closes and reopens on the new version.',
        'update_refused' => 'Beatrax downloaded version :version and refused to install it — the file did not match the publisher signature, so nothing on this device was changed. A damaged download can cause this. If it keeps happening, do not install Beatrax from that source.',
        'update_stale' => "You're on version :current — version :latest has been available for 30 days. Update now.",
        'update_critical' => 'Critical update available — version :version fixes :summary. Install as soon as possible.',
        'backup_corrupt_with_path' => 'The backup written at :timestamp failed integrity check. Inspect :path. Resolve before relying on backups.',
        'backup_corrupt_no_path' => 'The backup attempted at :timestamp aborted before any file was produced — source DB failed integrity check. Resolve before relying on backups.',
        'backup_write_failed' => 'The backup attempted at :timestamp did not complete — the database passed its checks, its backup files could not be written. Check free space and permissions on the backups folder.',
        'backup_restore_failed' => 'The restore attempted at :timestamp did not complete. Your previous data was saved first, to :snapshot.',

        'backup_overdue' => 'The most recent verified backup is :hoursh old. Beatrax makes this backup itself, once a day, while the app is open — there is nothing to run by hand. If it stays this old, the app has not been open when a daily run came round.',
        'backup_none_found' => 'No verified backup was found in the backups folder. Beatrax makes this backup itself, once a day, while the app is open — there is nothing to run by hand.',
        'wal_mode_missing' => 'The database is not in WAL mode (currently :mode), so saving can pause while a background task is running. Beatrax sets WAL every time it starts, so restarting usually clears this.',
        'synchronous_misconfigured' => 'The database durability level is :level rather than the expected NORMAL. Beatrax sets this every time it starts, so restarting usually clears it.',
        'oauth_scrub_set_failed' => 'OAuth secret redaction is offline. Logs and audit excerpts may contain unredacted tokens until the next successful load.',
        'oauth_reauth_required' => 'OAuth secrets moved to per-user storage. Re-authorize Gmail and Microsoft to resume email scanning. The old secrets file was renamed to :file for rollback.',
        'oauth_reconsent' => 'Reconnect your :provider',
        'auth_recovery_code_consumed' => 'Recovery code used by :username.',
        'auth_recovery_code_failed' => 'Failed recovery code attempt for :username.',
        'auth_lock_hard_cap_reached' => 'Signed out after too many failed PIN attempts.',
        'open_banking_reconsent' => 'Reconnect your bank',
        'open_banking_nothing_imported' => 'Your bank sent transactions, but Beatrax could not file any of them, so nothing reached your ledger. Open the Open banking settings to see why.',
        'auth_lock_corrupted_key' => 'Your PIN cannot open the app lock on this device: the stored key is unreadable. Sign in with your account password to set a new PIN.',
        'sync_gdk_rewrap_failed' => 'GDK keyring re-wrap failed after an app-lock passphrase change — encrypted data may be unrecoverable until the keyring is re-wrapped.',
        'worker_crashed' => 'Beatrax\'s background processing stopped unexpectedly. Imports and email scans are paused. Reopen the app to restart it.',
        'auth_lock_key_material_stranded' => 'At-rest encryption is active for this account but no app-lock wrap still holds the data key, so every encrypted note, description and counterparty detail reads as empty. Restore an encrypted backup made while the key still worked, or set this account up again on a device that still holds it.',
        'auth_lock_recovery_wrap_stale' => 'The account password changed without the app-lock recovery wrap being re-wrapped, so that password no longer opens the app lock. The PIN still does. Re-link the account password from the app-lock settings while the PIN is still known, or a forgotten PIN leaves nothing behind it.',
        'reconnect_link' => 'Reconnect →',
        'pots_category_link_retired' => 'Envelope budgeting has replaced category-linked pots. :amount from :count archived pot is unallocated again, and waiting for you to assign it.|Envelope budgeting has replaced category-linked pots. :amount from :count archived pots is unallocated again, and waiting for you to assign it.',
        'notifications_deferred_pass_failed' => 'Beatrax could not work out your :pass on this device, so some may be missing. It tries again each time you open the app.',
    ],
];
