<?php

declare(strict_types=1);

return [
    'heading_named' => 'Chain for :name',
    'heading' => 'Chain',

    'unresolved_heading' => 'Chain not yet resolved',
    'unresolved_body' => 'The chain resolver is still running. Open the review queue or refresh in a moment.',

    'none_heading' => 'No funding chain found',
    'none_body' => 'This transaction has no detected funding chain. If you expected one, file a candidate from the review queue.',

    'none_beyond_leg' => 'No funding chain found beyond this leg.',

    'covers_charges' => 'Covers :count ICS charge|Covers :count ICS charges',
    'no_ics_charges' => 'No ICS charges in this settlement',
    'show_more_fanout' => 'Show :count more · :shown of :total',

    'confirm' => 'Confirm',
    'reject' => 'Reject',
    'confirm_aria' => 'Confirm chain link :id',
    'reject_aria' => 'Reject chain link :id',

    'confidence_aria' => [
        'deterministic' => 'Confidence: deterministic match',
        'confirmed' => 'Confidence: confirmed',
        'candidate' => 'Confidence: candidate; needs review',
    ],
];
