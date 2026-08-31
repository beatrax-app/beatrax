<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Your credit card',
    'h1' => 'Grab your monthly statement PDFs',
    'lede' => 'Drop all your monthly PDF statements — we\'ll combine them into one preview.',

    'format_group_aria' => 'ICS exports as PDF only',
    'issuer_note' => 'ICS is the only card issuer we can read today, and only the Dutch-language statement it hands out. If your card is from another issuer, skip this step.',
    'got_it_as' => 'Got it as:',
    'badge_only_format' => 'only format',

    'mini' => [
        'login_label' => 'Log in',
        'statements_label' => 'Open statements',
        'months_label' => 'Pick months',
        'months_sub' => 'One PDF per month',
        'download_label' => 'Download',
    ],

    'drop_lead' => 'Drop your ICS PDFs here',
    'browse_files' => 'or browse for files',
    'queue_aria' => 'Queued PDF statements',

    'skip' => 'Skip this step',
    'continue' => 'Continue →',

    'errors' => [
        'required' => 'Drop the monthly PDF statements you downloaded from Mijn ICS.',
        'min' => 'Drop at least one ICS PDF statement before continuing.',
        'each_required' => 'Drop the monthly PDF statement you downloaded from Mijn ICS.',
        'each_max' => 'One of your files is too large. ICS PDF statements are normally under 1 MB each.',
        'each_extensions' => "One of your files isn't a PDF. Mijn ICS only exports PDF — try the latest monthly statement.",
        'file_unreadable' => 'Could not read :filename. The full error is in /dev/logs.',
        'none_readable' => "We couldn't read any of your ICS PDFs. :detail",
        'full_error_in_logs' => 'The full error is in /dev/logs.',
    ],
];
