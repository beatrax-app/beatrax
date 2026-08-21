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
        'add_contributions' => 'Add contributions to see a projection',
        'not_enough_history' => 'Not enough history to project a date yet',
        'no_recent_contributions' => 'No recent contributions to project from',
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
        'archive' => 'Archive',
        'restore' => 'Restore',
    ],

    'archived_disclosure' => 'Archived goals (:count)',

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
        'amount' => 'Enter a valid amount greater than zero.',
        'pot_linked_category' => 'This pot is linked to a category. Remove that link on the Pots page first.',
    ],
];
