<?php

declare(strict_types=1);

return [
    'help_paypal' => "PayPal exports don't carry balance lines, so set this manually.",
    'help_asn' => 'Auto-anchored from your latest statement. Override only if you know the live balance differs.',
    'help_default' => 'Override only if you know the current live balance differs from what beatrax computes.',

    'legend' => 'Forecast opening balance for :name',
    'opening_label' => 'Opening balance',
    'opening_placeholder' => 'e.g. 1.250,00',
    'as_of_label' => 'Opening balance as of',
    'as_of_help' => 'The date the figure above is true for.',

    'divergence' => 'This is more than €500 off the balance beatrax computes from your imported transactions. Are you sure?',
    'use_beatrax' => "Use beatrax's number",
    'use_mine' => 'Use my number',

    'save' => 'Save opening balance',
    'saved' => 'Saved.',

    'toast' => [
        'updated' => 'Opening balance updated.',
    ],

    'errors' => [
        'invalid_number' => 'Opening balance must be a valid number.',
        'date_required' => 'Pick the date this opening balance applies to.',
        'date_invalid' => 'Opening balance date must be a valid ISO date (YYYY-MM-DD).',
        'date_future' => 'Opening balance date cannot be in the future.',
    ],
];
