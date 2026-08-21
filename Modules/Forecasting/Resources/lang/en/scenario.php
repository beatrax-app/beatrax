<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Scenario editor — :name',
    'rename_aria' => 'Rename scenario',
    'save' => 'Save',
    'save_changes' => 'Save changes',
    'cancel' => 'Cancel',
    'rename' => 'Rename',
    'confirm_delete' => 'Confirm delete',
    'delete_scenario' => 'Delete scenario',
    'delete_confirm' => 'Delete this scenario?',

    'mutations_count' => 'Mutations (:count)',
    'no_mutations' => 'No mutations yet. Add one below to see how this scenario compares to your baseline.',
    'editing' => 'Editing — :kind',
    'edit' => 'Edit',
    'remove' => 'Remove',

    'add_mutation' => '+ Add mutation',
    'add_to_scenario' => 'Add to scenario',
    'pick_kind' => 'Pick a mutation kind:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Cancel a series',
            'desc' => 'Drop every projected occurrence of an approved series.',
        ],
        'add_one_off' => [
            'title' => 'Add a one-off charge or credit',
            'desc' => 'A single hypothetical event on a specific date.',
        ],
        'add_recurring' => [
            'title' => 'Add a recurring series',
            'desc' => 'A hypothetical new subscription or income stream.',
        ],
        'change_series_amount' => [
            'title' => 'Change a series amount',
            'desc' => 'Model a price hike or drop on an existing series.',
        ],
        'shift_series_date' => [
            'title' => 'Shift a series date',
            'desc' => 'Move the next or all subsequent occurrences forward.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Series to cancel',
        'pick_series' => '— pick a series —',
        'date' => 'Date',
        'amount' => 'Amount',
        'currency' => 'Currency',
        'direction' => 'Direction',
        'expense_long' => 'Expense (money out)',
        'income_long' => 'Income (money in)',
        'note' => 'Note (optional)',
        'start_date' => 'Start date',
        'expense' => 'Expense',
        'income' => 'Income',
        'cadence' => 'Cadence',
        'cadence_weekly' => 'Weekly',
        'cadence_monthly' => 'Monthly',
        'cadence_quarterly' => 'Quarterly',
        'cadence_yearly' => 'Yearly',
        'series' => 'Series',
        'new_amount' => 'New amount',
        'new_next_date' => 'New next date',
        'scope' => 'Scope',
        'scope_legend' => 'Which occurrences to shift',
        'scope_next' => 'Just the next occurrence',
        'scope_all' => 'All subsequent occurrences',
    ],

    'whatif' => [
        'trigger' => 'Model what-if',
        'menu_aria' => 'Model what-if for :name',
        'model_cancellation' => 'Model cancellation',
        'model_amount_change' => 'Model amount change…',
        'amount_dialog_aria' => 'Model amount change for :name',
        'current_amount' => 'Current amount',
        'new_amount' => 'New amount',
    ],

    'series_name_fallback' => 'series',

    'summary' => [
        'cancel' => 'Cancel :name',
        'series_fallback' => 'series #:id',
        'one_off' => ':amount :currency on :date',
        'recurring' => ':amount :currency :cadence from :date',
        'change_amount' => ':name: new amount :amount',
        'shift' => ':name: shift :scope to :date',
        'scope_all' => 'all subsequent',
        'scope_next' => 'next',
    ],

    'toast' => [
        'created' => 'Scenario ":name" created.',
        'deleted' => 'Scenario deleted.',
        'renamed' => 'Scenario renamed.',
        'mutation_added' => 'Mutation added.',
        'mutation_updated' => 'Mutation updated.',
        'mutation_removed' => 'Mutation removed. Undo',
    ],

    'errors' => [
        'name_empty' => 'Scenario name cannot be empty.',
        'name_too_long' => 'Scenario name must be :max characters or fewer.',
        'name_taken' => 'A scenario with that name already exists.',
        'pick_kind_first' => 'Pick a mutation kind first.',
        'amount_positive' => 'Amount must be a positive number.',
    ],
];
