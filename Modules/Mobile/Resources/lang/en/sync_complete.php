<?php

declare(strict_types=1);

return [
    'page_title' => 'This device is synced',
    'heading' => 'This device is synced',
    'records' => 'Copied :count record from :peer.|Copied :count records from :peer.',
    'records_none' => 'Caught up with :peer. There was nothing new to copy.',
    'withheld' => ':count change has not arrived yet.|:count changes have not arrived yet.',
    'withheld_action' => 'Signed by a device this one cannot check. Nothing is lost — it stays on :peer, and arrives once one of your devices passes on that identity and you confirm it under :section.',
    'how_it_works' => 'From here on',
    'automatic_title' => 'You choose when it syncs',
    'automatic_body' => 'Anything you change on either device shows up on the other the next time you tap :action. It cannot run in the background — the app lock holds the only key.',
    'lan_title' => 'On the same network',
    'lan_body' => 'When both devices are on your home network they talk to each other directly, without anything in between.',
    'relay_title' => 'When you are out',
    'relay_body' => 'Changes wait, encrypted, on your relay until the other device comes back online. This device collects them the next time you tap :action.',
    'no_relay_title' => 'When you are out',
    'no_relay_body' => 'Changes wait on this device until both are on your home network together and you tap :action here.',
    'encrypted_title' => 'Only your devices can read it',
    'encrypted_body' => 'Everything is encrypted before it leaves a device, and only your paired devices hold the keys.',
    'continue' => 'Start using Beatrax',
    'peer_fallback' => 'your other device',
];
