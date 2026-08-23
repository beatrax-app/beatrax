<?php

declare(strict_types=1);

return [
    'title' => 'Review recurring',
    'subtitle' => 'Approve, snooze, or reject detected recurring suggestions.',

    'tabs' => [
        'pending' => 'Pending',
        'rejected' => 'Rejected',
        'cadence_changed' => 'Cadence changed',
    ],

    'bulk' => [
        'aria' => 'Bulk actions',
        'selected' => ':count selected',
        'approve' => 'Approve :count',
        'reject' => 'Reject :count',
    ],

    'empty' => [
        'heading' => 'Nothing to review',
        'pending' => 'Recurring suggestions land here as the detector spots stable monthly clusters.',
        'rejected' => 'Rejected suggestions appear here so you can bring them back if your mind changes.',
        'cadence_changed' => 'Approved series whose cadence has flipped show up here for re-review.',
    ],

    'next' => 'Next',
    'cadence_changed_note' => 'cadence changed',
    'un_reject' => 'Un-reject',
    'approve' => 'Approve',
    'approve_aria' => 'Approve recurring series :id',
    'reject' => 'Reject',
    'reject_aria' => 'Reject recurring series :id',
    'snooze' => 'Snooze',
    'snooze_aria' => 'Snooze recurring series :id',
    'snooze_1w' => '1 week',
    'snooze_1m' => '1 month',
    'snooze_3m' => '3 months',
    'edit_name' => 'Edit name',
    'edit_name_aria' => 'Rename recurring series :id',
    'new_name_label' => 'New name for this series',
    'save' => 'Save',

    'toast' => [
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'snoozed' => 'Snoozed',
        'renamed' => 'Renamed',
        'un_rejected' => 'Un-rejected',
        'bulk_approved' => ':count approved',
        'bulk_rejected' => ':count rejected',
    ],
];
