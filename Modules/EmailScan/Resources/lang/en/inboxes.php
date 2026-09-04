<?php

declare(strict_types=1);

return [
    'heading' => 'Inboxes',
    'intro' => 'Connect Gmail and Microsoft 365 inboxes so Beatrax can scan them for receipts.',
    'intro_phone' => 'Inbox scanning runs in the desktop app, not on this phone.',

    'phone_heading' => 'This phone does not scan mailboxes',
    'phone_body' => 'Connect Gmail or Microsoft 365 in the desktop app — the receipts it finds arrive here over sync.',
    'connection_canceled' => 'Connection canceled.',
    'connection_failed' => "Couldn't complete the connection.",

    'backfilling' => 'Backfilling',
    'backfill_progress' => ':fetched / ~:count message|:fetched / ~:count messages',

    'connect_heading' => 'Connect your email',
    'connect_body' => 'Import receipts from PayPal, ICS Cards, Google Play, and other merchants by giving Beatrax read-only access to one or more of your inboxes.',
    'connect_body_phone' => 'Receipts from PayPal, ICS Cards, Google Play, and other merchants are imported by the desktop app, from the inboxes you give it read-only access to. This phone shows what that import finds.',
    'connect_gmail' => 'Connect Gmail',
    'connect_microsoft' => 'Connect Microsoft 365',
    'readonly_note' => 'Beatrax only reads messages. It never sends, labels, moves, or deletes anything in your inbox.',

    'months' => ':count month|:count months',
    'not_scanned_yet' => 'not scanned yet',
    'not_scanned_yet_phone' => 'not scanned on this phone',
    'last_scanned' => 'last scanned',
    'window_prefix' => 'Window:',
    'edit' => 'Edit',

    'badge' => [
        'idle' => 'Idle',
        'backfilling' => 'Backfilling',
        'scanning' => 'Scanning',
        'rate_limited' => 'Rate limited',
        'needs_reauth' => 'Needs reauth',
        'error' => 'Error',
    ],

    'error_detail' => "The last scan didn't finish. Try Scan now, or reconnect this inbox.",
    'oauth_state_mismatch' => 'That connection link has expired or was already used. Start the connection again.',
    'oauth_no_code' => 'Your mail provider sent you back without the code Beatrax needs to finish, so no mailbox was connected. Start the connection again.',
    'oauth_grant_refused' => 'Your mail provider refused the permission Beatrax was granted — it has expired or been withdrawn. Start the connection again and approve it.',
    'oauth_exchange_failed' => 'Your mail provider did not complete the connection, so no mailbox was added. Try again in a few minutes.',
    'oauth_not_saved' => 'The connection could not be saved on this device, so no mailbox was added. Try again — if it keeps failing, the app log records what stopped it.',
    'oauth_no_offline_access_google' => 'Google did not grant the lasting permission Beatrax needs, so this mailbox would stop scanning within the hour. Publish your OAuth consent screen to production, then connect again.',
    'oauth_no_offline_access' => 'Your mail provider did not grant the lasting permission Beatrax needs, so this mailbox would stop scanning within the hour. Connect again and allow offline access when you are asked.',
    'oauth_no_offline_access_google_phone' => 'Google did not grant the lasting permission Beatrax needs, so no mailbox was connected. Publish your OAuth consent screen to production, then connect again — the scanning itself runs in the desktop app.',
    'oauth_no_offline_access_phone' => 'Your mail provider did not grant the lasting permission Beatrax needs, so no mailbox was connected. Connect again and allow offline access when you are asked — the scanning itself runs in the desktop app.',

    'retry_seconds' => 'retrying in :ns',
    'retry_minutes' => 'retrying in :nm',
    'retry_hours' => 'retrying in :nh',

    'reconnect' => 'Reconnect',
    'disconnect' => 'Disconnect',
    'scan_now' => 'Scan now',
    'scan_in_progress_title' => 'Scan already in progress',

    'add_another' => 'Add another inbox',
    'gmail_card_body' => 'Connect a Gmail account so Beatrax can scan it for receipts.',
    'microsoft_card_body' => 'Connect a Microsoft 365 or Outlook.com account so Beatrax can scan it for receipts.',
    'gmail_card_body_phone' => 'Gmail is scanned by the desktop app. An account connected here is never scanned on its own.',
    'microsoft_card_body_phone' => 'Microsoft 365 and Outlook.com are scanned by the desktop app. An account connected here is never scanned on its own.',

    'discovered_heading' => 'Discovered senders',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (statements)',
    ],
    'discovered_body' => "Senders that look like they send receipts but aren't on your known-receipts list yet. Add the ones you want Beatrax to scan; dismiss the rest.",
    'last_seen' => 'last seen',
    'seen_times' => 'Seen :count time|Seen :count times',
    'add' => 'Add',
    'add_aria' => 'Add :email',
    'dismiss' => 'Dismiss',
    'dismiss_aria' => 'Dismiss :email',

    'toast' => [
        'reconnect_first' => 'Reconnect this inbox before scanning.',
        'scan_in_progress' => 'Scan already in progress.',
        'scan_started' => 'Scan started.',
        'sender_added' => 'Sender added.',
        'sender_dismissed' => 'Sender dismissed.',
    ],
];
