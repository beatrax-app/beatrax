<?php

declare(strict_types=1);

return [
    'about_body' => 'A bundled YAML file mapping cryptic bank-statement codes to friendly merchant names. Toggling on lets Beatrax read the list when you import; submitting a suggestion opens GitHub in your browser.',

    'mappings' => ':count mapping|:count mappings',
    'contributors' => ':count contributor|:count contributors',

    'use_shared_list' => [
        'title' => 'Use the shared merchant list',
        'help' => 'Let Beatrax read the bundled list to fill in friendly names for merchants you have not renamed yourself.',
    ],

    'offer_to_contribute' => [
        'title' => 'Offer to contribute',
        'help' => 'Show the "Help others identify this" CTA on the triage row so you can submit a suggestion to the shared list with one click.',
    ],

    'update_on_updates' => [
        'title' => 'Update the shared list on app updates',
        'help' => 'Refresh the bundled list every time Beatrax updates itself.',
        'help_phone' => 'Refresh the bundled list every time a new version of Beatrax is installed from the App Store or Google Play.',
        'note' => 'Activates with a future app update — see Settings → About for the current version.',
    ],
];
