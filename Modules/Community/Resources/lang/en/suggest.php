<?php

declare(strict_types=1);

return [
    'heading' => 'Suggest a mapping',
    'intro' => 'Opens GitHub in your browser with the suggestion filled in. Only the pattern, name, category and region above go with it — and the pattern is the description as your statement wrote it. Your name and email never leave this device.',

    'pattern' => 'Pattern',
    'name' => 'Friendly name',
    'name_placeholder' => 'e.g. Albert Heijn',
    'category' => 'Category (optional)',
    'category_placeholder' => 'e.g. Groceries',
    'region' => 'Region',

    'regions' => [
        'other' => 'Other',
    ],

    'yaml_preview' => 'YAML preview',

    'cancel' => 'Cancel',
    'submit' => 'Open on GitHub',

    'toast' => 'Suggestion opened in your browser.',

    'errors' => [
        'pattern_required' => 'Pattern is required.',
        'name_required' => 'Name is required.',
        'browser_refused' => 'Your browser could not be opened, so nothing was sent and nothing left this device. Try again, or copy the YAML preview above into a pull request yourself.',
    ],
];
