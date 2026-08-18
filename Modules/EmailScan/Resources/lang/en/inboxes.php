<?php

declare(strict_types=1);

return [
    'heading' => 'Inboxes',
    'intro' => 'Connect Gmail and Microsoft 365 inboxes so Beatrax can scan them for receipts.',

    'connection_canceled' => 'Connection canceled.',
    'connection_failed' => "Couldn't complete the connection.",

    'backfilling' => 'Backfilling',
    'messages_suffix' => 'messages',

    'connect_heading' => 'Connect your email',
    'connect_body' => 'Import receipts from PayPal, ICS Cards, Google Play, and other merchants by giving Beatrax read-only access to one or more of your inboxes.',
    'connect_gmail' => 'Connect Gmail',
    'connect_microsoft' => 'Connect Microsoft 365',
    'readonly_note' => 'Beatrax only reads messages. It never sends, labels, moves, or deletes anything in your inbox.',

    'month' => '1 month',
    'months' => ':count months',
    'not_scanned_yet' => 'not scanned yet',
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

    'discovered_heading' => 'Discovered senders',
    'discovered_body' => "Senders that look like they send receipts but aren't on your known-receipts list yet. Add the ones you want Beatrax to scan; dismiss the rest.",
    'last_seen' => 'last seen',
    'seen_times' => 'Seen :count times',
    'add' => 'Add',
    'add_aria' => 'Add :email',
    'dismiss' => 'Dismiss',
    'dismiss_aria' => 'Dismiss :email',

    'toast' => [
        'scan_in_progress' => 'Scan already in progress.',
        'scan_started' => 'Scan started.',
        'sender_added' => 'Sender added.',
        'sender_dismissed' => 'Sender dismissed.',
    ],
];
