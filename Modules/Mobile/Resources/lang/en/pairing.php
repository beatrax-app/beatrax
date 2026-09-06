<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Paired device',
    'page_title' => 'Pair a device',

    'scan_heading' => 'Pair this device',
    'scan_subtitle' => 'Point the camera at the code shown on the other device.',
    'camera_permission_pending' => 'Camera access is off. Allow it for Beatrax in your device settings, then try again.',
    'open_camera' => 'Open the camera',
    'opening_camera' => 'Waiting for camera access…',
    'close_camera' => 'Close the camera',
    'viewfinder_aria' => 'Camera viewfinder — point it at the code on your other device',
    'viewfinder_idle' => 'The camera is off. Open it to scan the code shown on your other device.',
    'scan_prompt' => 'Scan the code on your other device',
    'enter_code_instead' => 'Enter code instead',

    'enter_heading' => 'Enter the code',
    'camera_off' => 'Camera access is off. Enter the code from the other device instead.',
    'camera_off_no_search' => 'Camera access is off, and searching the network for the other device does not work on iPhone yet — so a typed code cannot find it on its own. Turn camera access back on for Beatrax in your device settings and scan the code shown on the other device, or submit the code here and this screen will ask you where the other device is.',
    'no_search' => 'Searching the network for the other device does not work on iPhone yet, so a typed code cannot find it on its own. Scan the code with the camera instead — the camera needs no network search. If you cannot scan, submit the code and this screen will ask you where the other device is.',
    'word_code_aria' => 'Enter the word code from the other device',
    'initiator_address' => 'Where is the other device?',
    'initiator_address_help' => 'Its address on this network, as host and port. The desktop shows this under Devices and sync. Submit the code again once you have entered it.',
    'submit_code' => 'Submit code',
    'cancel' => 'Cancel',
    'skip_import' => 'Continue without importing',

    'confirm_heading' => 'Compare these words with the other device',
    'safety_words_aria' => 'Safety number words: :words',
    'confirm_body' => 'Both devices must show the exact same words. If they differ, tap Cancel — a man-in-the-middle attack may be in progress.',
    'awaiting_peer' => 'Waiting for the other device to confirm...',
    'confirm_match' => 'Confirm — they match',

    'success_heading' => 'Device paired',
    'success_body' => 'This device is now trusted. Your data will sync once you connect.',
    'encryption_incomplete' => 'This device is paired, but encrypting the data stored on it did not finish. It is not encrypted at rest yet.',
    'done' => 'Done',

    'errors' => [
        'relay_unreachable' => 'Cannot reach the other device. Make sure both are on the same network and sync is enabled on the desktop.',
        'no_road_home' => 'This device cannot search the network, and the code you scanned carried no address to reach the other device with. Ask it to show a fresh code, then scan that one.',
        'invalid_code' => 'This code is invalid or has expired. Ask the other device to generate a new one.',
        'already_under_way' => 'This device has already taken that code up, and is waiting for the other device to confirm. If it never does, ask for a fresh code and use that.',
        'vouched_but_refused' => 'The other device still holds that code, but this device could not take it up. Ask it for a fresh code and use that.',
        'code_incomplete' => 'That is not a complete code. Check it against the other device and type all of it.',
        'initiator_address_invalid' => 'That is not an address this device can dial. Enter it as host and port, for example 192.168.1.20:8100.',
        'code_not_accepted' => 'No device on this network accepted that code. Check the code, and that the other device is still showing it.',
        'no_peer_answered' => 'Nothing on this network answered that code. Check that sync is running on the other device, or scan its code with the camera — the camera needs no network search.',
        'no_peer_answered_ios' => 'Nothing on this network answered that code. Searching the network for the other device does not work on iPhone yet, so scan its code with the camera instead.',
        'no_peer_answered_camera_off' => 'Nothing on this network answered that code. Searching the network for the other device does not work on iPhone yet, and camera access is off — so turn camera access back on for Beatrax in your device settings, then scan the code shown on the other device.',
        'rate_limited' => 'Too many attempts. Wait a minute and try again.',
        'identity_locked' => 'Your device identity is locked. Unlock the app and try again.',
        'identity_needs_lock' => 'Set up the app lock first — your device identity is protected by it.',
        'safety_number_changed' => 'The other device changed while you were comparing. Check the words below against it again before confirming.',
    ],
];
