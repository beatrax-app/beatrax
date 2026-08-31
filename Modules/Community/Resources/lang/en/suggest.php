<?php

declare(strict_types=1);

return [
    'heading' => 'Suggest a mapping',
    'intro' => 'Opens GitHub in your browser so you can submit the suggestion as a draft PR. Your name and email never leave this device.',

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
    'submit' => 'Submit as draft PR',

    'toast' => 'Suggestion opened in your browser.',

    'errors' => [
        'pattern_required' => 'Pattern is required.',
        'name_required' => 'Name is required.',
        'browser_refused' => 'Your browser could not be opened, so nothing was sent and nothing left this device. Try again, or copy the YAML preview above into a pull request yourself.',
    ],
];
