<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Your PayPal account',
    'h1' => 'Connect your PayPal account',

    'lede_html' => 'Drop your PayPal activity export — one row per transaction, not the balance summary. PayPal names its reports in your account’s own language, and today we read the Dutch pair: <em lang="nl">Rapport Transactiegegevens</em>, not <span lang="nl">Saldorapport</span>. If yours comes out in another language, switch PayPal to Dutch before you download.',

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

    'drop_lead' => 'Drop your activity export here',
    'browse_file' => 'or browse for a file',

    'file_ready' => '· ✓ ready',

    'skip' => 'Skip this step',
    'continue' => 'Continue →',

    'errors' => [
        'required' => 'Drop your PayPal activity export into the box first.',
        'max' => 'That file is too large. A PayPal activity export is normally well under 10 MB.',
        'extensions' => 'That file doesn\'t look like a PayPal CSV. Download the activity export — one row per transaction, not the balance summary — as CSV.',
        'unreadable' => 'Could not read this file. The full error is in /dev/logs.',
    ],
];
