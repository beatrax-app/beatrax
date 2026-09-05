<?php

declare(strict_types=1);

return [
    'title' => 'Pair a new device',
    'step_1_of_3' => 'Step 1 of 3',
    'step_2_of_3' => 'Step 2 of 3',
    'step_3_of_3' => 'Step 3 of 3',

    'show_my_code' => 'Show my code',
    'show_my_code_help' => "Show this device's code for the other device to read.",
    'enter_a_code' => 'Enter a code',
    'safety_number_changed' => 'The other device changed while you were comparing. Check the words below against it again before confirming.',
    'enter_a_code_help' => 'Type the code shown on the other device.',

    'show_this_code' => 'Show this code',
    'enter_on_other' => 'Enter this code on the other device, or let it scan the QR.',
    'scan_on_other' => "Scan this code with the other device's camera. A computer has no camera — show its code and enter that here instead.",
    'expires_in' => 'Expires in',
    'code_expired' => 'Code expired.',
    'generate_new_code' => 'Generate new code',
    'cancel_pairing' => 'Cancel pairing',

    'enter_the_code' => 'Enter the code',
    'enter_code_aria' => 'Enter the word code from the other device',
    'submit_code' => 'Submit code',

    'compare_words' => 'Compare these words with the other device',
    'safety_number_words' => 'Safety number words:',
    'compare_help' => 'Both devices must show the exact same words. If they differ, tap Cancel pairing — a man-in-the-middle attack may be in progress.',
    'waiting_for_peer' => 'Waiting for the other device to confirm...',
    'confirm_match' => 'Confirm — they match',

    'device_paired' => 'Device paired',
    'device_paired_help' => 'This device is now trusted. Your data will sync once you connect.',
    'done' => 'Done',

    'identity_locked' => 'Your device identity is locked. Unlock the app and try again.',
    'invalid_code' => 'This code is invalid or has expired. Ask the other device to generate a new one.',
    'already_under_way' => 'This device has already taken that code up, and is waiting for the other device to confirm. If it never does, ask for a fresh code and use that.',
    'vouched_but_refused' => 'The other device still holds that code, but this device could not take it up. Ask it for a fresh code and use that.',
    'code_incomplete' => 'That is not a complete code. Check it against the other device and type all of it.',
    'code_not_accepted' => 'No device on this network accepted that code. Check the code, and that the other device is still showing it.',
    'no_peer_answered' => 'Nothing on this network answered that code. Check that sync is running on the other device.',
    'no_peer_search' => "This device could not search the network, so it could not check that code. Show this device's code instead and enter it on the other one.",
    'rate_limited' => 'Too many attempts. Wait a minute and try again.',
];
