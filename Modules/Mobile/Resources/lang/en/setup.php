<?php

declare(strict_types=1);

return [
    'blocked' => [
        'no_peer' => 'Waiting for the other device to finish confirming.',
        'no_keys' => 'Waiting for the encryption keys from the other device.',
        'unreachable' => 'Cannot reach the other device — check both are on the same network.',
        'reprojecting' => 'Rebuilding your history…',
        'retrying' => 'Reconnecting to the other device…',
        'locked' => 'Unlock the app to continue setting up.',
        'revoked' => 'This device was removed from your other device. Pair again to resume syncing.',
    ],
    'unlock_cta' => 'Unlock the app',
    'step' => [
        'connect' => 'Connecting to your other device',
        'keys' => 'Receiving encryption keys',
        'transfer' => 'Transferring your history',
        'rebuild' => 'Rebuilding your history',
    ],
    'step_current' => 'current step',
    'page_title' => 'Setting up…',
    'resuming' => 'Resuming setup…',
    'setting_up' => 'Setting up this device…',
    'progress_aria' => 'Setup progress',
    'records' => ':count record|:count records',
];
