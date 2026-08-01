<?php

declare(strict_types=1);

return [
    'page_title' => 'Chain hints',
    'heading' => 'Hints',
    'back_to_review' => '← Back to review queue',
    'subtitle' => "Candidates a matcher emitted without a matching partner. Each hint either resolves itself on the next chain pass, or you can dismiss it here once you've decided it isn't going to.",

    'empty_heading' => 'No hints to triage',
    'empty_body' => "When a matcher surfaces a chain it couldn't auto-resolve, it'll show up here.",

    'no_counterparty' => '(no counterparty)',
    'unknown_account' => '(unknown account)',

    'dismiss' => 'Dismiss',
    'dismiss_aria' => 'Dismiss hint :id',
    'dismissed' => 'Hint dismissed.',

    'kind' => [
        'ics_bulk_settle' => 'Bulk iDEAL settlement (out of tolerance)',
        'funded_by_card_hint' => 'Funded by card (hint)',
        'refund_of_hint' => 'Refund (hint)',
    ],
];
