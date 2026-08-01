<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Your bank',
    'h1' => 'Grab a statement, then drop it below',
    'lede' => 'Pick the format your bank gave you, then drop the file. We auto-detect CAMT.053 and MT940.',

    'format_group_aria' => 'Bank statement format',
    'got_it_as' => 'Got it as:',
    'badge_recommended' => 'recommended',

    'mini' => [
        'login_label' => 'Log in',
        'login_sub' => "Your bank's website",
        'statements_label' => 'Open statements',
        'statements_sub' => "In your bank's menu",
        'range_label' => 'Pick a range',
        'range_sub' => 'Last 90 days',
        'download_label' => 'Download',
    ],

    'csv_picker_aria' => 'Which bank exported your CSV?',
    'csv_picker_from' => 'From:',

    'drop_lead_camt053' => 'Drop your CAMT.053 file here',
    'drop_lead_mt940' => 'Drop your MT940 file here',
    'drop_lead_asn' => 'Drop your ASN CSV here',
    'drop_lead_ing' => 'Drop your ING CSV here',
    'drop_lead_pick_bank' => 'Pick which bank exported your CSV — we need to know to read it correctly.',
    'drop_lead_default' => 'Drop your statement file here',
    'browse_file' => 'or browse for a file',

    'banks_mt940' => 'Supported: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Supported: ASN, ING — more formats coming as users contribute samples.',
    'banks_default' => 'Supported: ASN, ING',

    'file_ready' => '· ✓ ready',

    'skip' => 'Skip this step',
    'continue' => 'Continue →',

    'errors' => [
        'file_required' => 'Drop your statement file into the box first.',
        'file_max' => 'That file is too large. Drop a statement under 10 MB.',
        'file_extensions' => "That file doesn't look like a bank statement. Drop a CAMT.053 XML, CSV, or MT940 file.",
        'pick_bank' => 'Pick which bank exported your CSV before continuing.',
        'unreadable' => 'Could not read this file. The full error is in /dev/logs.',
    ],
];
