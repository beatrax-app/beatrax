<?php

declare(strict_types=1);

return [
    'page_title' => 'This device is synced',
    'heading' => 'This device is synced',
    'records' => 'Copied :count record from :peer.|Copied :count records from :peer.',
    'records_none' => 'Caught up with :peer. There was nothing new to copy.',
    'how_it_works' => 'From here on',
    'automatic_title' => 'It keeps itself up to date',
    'automatic_body' => 'Anything you change on either device shows up on the other. There is no sync button to press.',
    'lan_title' => 'On the same network',
    'lan_body' => 'When both devices are on your home network they talk to each other directly, without anything in between.',
    'relay_title' => 'When you are out',
    'relay_body' => 'Changes wait, encrypted, on your relay until the other device comes back online, then they land automatically.',
    'no_relay_title' => 'When you are out',
    'no_relay_body' => 'Changes wait on this device and sync the next time both are on your home network together.',
    'encrypted_title' => 'Only your devices can read it',
    'encrypted_body' => 'Everything is encrypted before it leaves a device, and only your paired devices hold the keys.',
    'continue' => 'Start using Beatrax',
    'peer_fallback' => 'your other device',
];
