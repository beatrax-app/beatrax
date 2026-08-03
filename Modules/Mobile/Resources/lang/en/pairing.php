<?php

declare(strict_types=1);

return [
    'page_title' => 'Pair a device',

    'scan_heading' => 'Pair this device',
    'scan_subtitle' => 'Point the camera at the code shown on the other device.',
    'open_camera' => 'Open the camera',
    'close_camera' => 'Close the camera',
    'viewfinder_aria' => 'Camera viewfinder — point it at the code on your other device',
    'viewfinder_idle' => 'The camera is off. Open it to scan the code shown on your other device.',
    'scan_prompt' => 'Scan the code on your other device',
    'enter_code_instead' => 'Enter code instead',

    'enter_heading' => 'Enter the code',
    'camera_off' => 'Camera access is off. Enter the code from the other device instead.',
    'word_code_aria' => 'Enter the word code from the other device',
    'submit_code' => 'Submit code',
    'cancel' => 'Cancel',

    'confirm_heading' => 'Compare these words with the other device',
    'safety_words_aria' => 'Safety number words: :words',
    'confirm_body' => 'Both devices must show the exact same words. If they differ, tap Cancel — a man-in-the-middle attack may be in progress.',
    'awaiting_peer' => 'Waiting for the other device to confirm...',
    'confirm_match' => 'Confirm — they match',

    'success_heading' => 'Device paired',
    'success_body' => 'This device is now trusted. Your data will sync once you connect.',
    'done' => 'Done',

    'errors' => [
        'import_needs_qr' => 'Scan the QR code shown on the other device to import.',
        'invalid_code' => 'This code is invalid or has expired. Ask the other device to generate a new one.',
        'identity_locked' => 'Your device identity is locked. Unlock the app and try again.',
    ],
];
