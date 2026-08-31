<?php

declare(strict_types=1);

return [
    'page_title' => 'Data & devices',
    'heading' => 'Data & devices',
    'sync_status' => 'Sync status',
    'syncing_progress' => 'Syncing… :count record|Syncing… :count records',
    'initial_sync_aria' => 'Initial sync progress',
    'no_peers' => 'Pair another device to start syncing.',
    'sync_now' => 'Sync now',
    'result' => [
        'synced' => 'Synced with your other device.',
        'unreachable' => 'Could not reach your other device — check both are on the same network.',
        'locked' => 'Unlock the app to sync.',
        'not_enabled' => 'Sync is not set up on this device yet.',
        'unreadable' => 'The key on this device no longer opens. Pair again to resume syncing.',
        'paused_on_cellular' => 'Paused — sync is set to Wi-Fi only and you are on mobile data.',
    ],
    'background_note' => 'Syncing happens when you tap Sync now. It cannot run in the background — the app lock holds the only key.',
    'network' => 'Network',
    'pause_cellular' => 'Pause sync on cellular',
    'pause_cellular_help' => 'Off by default — sync works everywhere. Turn on to only sync over Wi-Fi.',
];
