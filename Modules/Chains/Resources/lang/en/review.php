<?php

declare(strict_types=1);

return [
    'page_title' => 'Review chains',
    'heading' => 'Review chains',
    'hint_singular' => 'hint',
    'hint_plural' => 'hints',
    'subtitle' => 'Confirm or reject candidate links the chain resolver could not auto-confirm.',

    'empty_heading' => 'Nothing to review',
    'empty_body' => 'Every chain link is either confirmed or rejected. New candidates will appear here as imports land.',

    'auto_confirm_nudge' => 'One more confirm and similar links auto-confirm.',

    'confirm' => 'Confirm',
    'reject' => 'Reject',
    'confirm_aria' => 'Confirm chain link :id',
    'reject_aria' => 'Reject chain link :id',
    'show_more' => 'Show more',

    'kind' => [
        'paypal_funding' => 'PayPal funding',
        'ics_bulk_settle' => 'Bulk iDEAL settlement',
    ],

    'errors' => [
        'confirm_hint' => 'This candidate is a hint — open it to attach the matching transaction before confirming.',
        'reject_hint' => 'This candidate is a hint — open it to attach the matching transaction before rejecting.',
    ],
];
