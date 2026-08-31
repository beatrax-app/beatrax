<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Goals',
        'subtitle' => 'Track progress toward your savings targets.',
        'add_goal' => 'Add goal',
    ],

    'empty' => [
        'heading' => 'No goals yet',
        'body' => 'Set a target amount and date to start tracking your savings progress.',
        'add_first' => 'Add your first goal',
    ],

    'status' => [
        'overdue' => 'Overdue',
        'reached' => 'Reached',
        'completed' => 'Completed',
        'archived' => 'Archived',
    ],

    'row' => [
        'edit' => 'Edit',
    ],

    'progress' => [
        'aria' => ':name: :pct% complete',
    ],

    'card' => [
        'target_date' => 'Target date: :date',
    ],

    'projection' => [
        'target_reached' => 'Target reached',
        'closed_short' => 'Closed before the target',
        'add_contributions' => 'Add contributions to see a projection',
        'not_enough_history' => 'Not enough history to project a date yet',
        'no_recent_contributions' => 'No recent contributions to project from',
        'too_far_to_date' => 'Too far off to date at this rate',
        'est' => 'Est. :date ·',
        'projection_note' => '(projection)',
        'projected' => 'Projected: :date',
    ],

    'archive' => [
        'confirm_question' => 'Archive this goal?',
        'close' => 'Close',
        'confirm_aria' => 'Confirm archive of :name',
        'archive' => 'Archive',
    ],

    'actions' => [
        'more_aria' => 'More actions for :name',
        'mark_complete' => 'Mark as complete',
        'mark_complete_caption' => 'Complete',
        'archive' => 'Archive',
        'restore' => 'Restore',
    ],

    'archived_disclosure' => 'Archived goal (:count)|Archived goals (:count)',

    'form' => [
        'title_edit' => 'Edit goal',
        'title_create' => 'Create a savings goal',
        'subtitle_edit' => 'Update the name, target, date, or linked pot.',
        'subtitle_create' => 'Set a target amount and date to track your savings progress.',
        'name' => 'Name',
        'name_placeholder' => 'e.g. Emergency fund',
        'target_amount' => 'Target amount (:currency)',
        'target_date' => 'Target date',
        'linked_pot' => 'Linked pot (optional)',
        'no_pot' => 'No pot — use transfer tracking',
        'linked_pot_help' => "When linked, the pot's balance drives this goal's progress.",
        'save_changes' => 'Save changes',
        'save_goal' => 'Save goal',
        'close' => 'Close',
    ],

    'summary' => [
        'see_all' => 'See all →',
        'no_goals' => 'No goals yet.',
        'add_first' => 'Add your first goal →',
    ],

    'notices' => [
        'goal_created' => 'Goal created.',
        'goal_updated' => 'Goal updated.',
        'goal_marked_complete' => 'Goal marked as complete.',
        'goal_archived' => 'Goal archived.',
        'goal_restored' => 'Goal restored.',
    ],

    'errors' => [
        'name' => 'Enter a name for your goal.',
        'date' => 'Choose a target date.',
        'date_invalid' => 'Choose a real date.',
        'date_before_start' => 'Choose a date on or after the goal\'s start date.',
        'generic' => 'That goal could not be saved. Check the fields and try again.',
        'amount' => 'Enter a valid amount greater than zero.',
        'pot_linked_category' => 'This pot is linked to a category. Remove that link on the Pots page first.',
        'pot_already_linked' => 'That pot already funds another goal. Unlink it there first.',
        'pot_missing' => 'That pot is no longer available. Pick another one, or leave this goal unlinked.',
    ],
];
