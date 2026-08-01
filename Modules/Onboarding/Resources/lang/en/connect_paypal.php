<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Your PayPal account',
    'h1' => 'Connect your PayPal account',

    'lede_html' => 'Drop your PayPal transaction details export — listed as <em lang="nl">Rapport Transactiegegevens</em> in a Dutch PayPal account. The balance report (<span lang="nl">Saldorapport</span>) won\'t work — we need per-event data.',

    'format_group_aria' => 'PayPal exports as CSV only',
    'got_it_as' => 'Got it as:',
    'badge_only_format' => 'only format',

    'mini' => [
        'login_label' => 'Log in',
        'custom_label' => 'Custom statements',
        'range_label' => 'Pick a range',
        'range_sub' => 'Last 12 months',
        'download_label' => 'Download as CSV',
    ],

    'drop_lead' => 'Drop your transaction details CSV here',
    'browse_file' => 'or browse for a file',

    'file_ready' => '· ✓ ready',

    'skip' => 'Skip this step',
    'continue' => 'Continue →',

    'errors' => [
        'required' => 'Drop your PayPal Rapport Transactiegegevens CSV into the box first.',
        'max' => 'That file is too large. PayPal Rapport Transactiegegevens exports are normally well under 10 MB.',
        'extensions' => "That file doesn't look like a PayPal CSV. Download Rapport Transactiegegevens (not the Saldorapport balance report) as CSV from PayPal.",
        'unreadable' => 'Could not read this file. The full error is in /dev/logs.',
    ],
];
