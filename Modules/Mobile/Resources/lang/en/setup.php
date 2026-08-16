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
    ],
    'step' => [
        'connect' => 'Connecting to your other device',
        'keys' => 'Receiving encryption keys',
        'transfer' => 'Transferring your history',
        'rebuild' => 'Rebuilding your history',
    ],
    'step_current' => 'current step',
    'working' => [
        'connect' => 'Reaching your other device…',
        'keys' => 'Unlocking your data…',
        'transfer' => 'Asking for your history…',
        'rebuild' => 'Rebuilding your history — this can take a minute.',
    ],
    'page_title' => 'Setting up…',
    'resuming' => 'Resuming setup…',
    'setting_up' => 'Setting up this device…',
    'progress_aria' => 'Setup progress',
    'records' => ':applied of :expected records',
    'records_preparing' => 'Waiting for the other device…',
];
