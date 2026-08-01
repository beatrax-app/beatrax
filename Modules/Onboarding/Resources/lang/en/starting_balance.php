<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 STARTING BALANCE',
    'confirmed_aria' => 'confirmed',
    'on_date' => 'on :date',

    'detected_h3' => 'We detected your :label started at',
    'confirm' => 'Confirm',
    'edit' => 'Edit',

    'conflict_h3' => 'We saw two values for this account — which is right?',
    'conflict_legend' => 'Pick a starting balance',
    'conflict_from' => 'From :source:',
    'conflict_helper' => 'We default to the earliest date. Pick the right one or edit manually.',
    'edit_manually' => 'Edit manually',

    'editing_h3' => 'Edit your :label starting balance',
    'input_label' => 'STARTING BALANCE',
    'minor_units' => '(minor units)',
    'on_date_label' => 'ON DATE',
    'cancel' => 'Cancel',
    'save' => 'Save',

    'change' => 'Change',

    'manual_h3' => 'Enter your :label starting balance manually',
    'manual_lede' => "We couldn't auto-detect a starting balance for this account. Enter one manually or skip.",

    'unknown_state' => 'Unknown card state. Reload the wizard.',

    'errors' => [
        'account_not_set' => 'Account not set. Reload the wizard.',
        'invalid_amount' => 'Enter a valid amount.',
        'amount_range' => 'Enter an amount between -€10M and €10M.',
        'pick_date' => 'Pick a date.',
        'pick_valid_date' => 'Pick a valid date.',
        'future_date' => 'Starting balance date cannot be in the future.',
        'date_warning' => 'This is later than your first imported transaction (:date). Your dashboard may show transactions before this date.',
    ],
];
