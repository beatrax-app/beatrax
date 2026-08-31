<?php

declare(strict_types=1);

return [
    'page_title' => 'Transaction',
    'heading' => 'Transaction',
    'booked_on' => 'Booked :date',

    'counterparty' => 'Counterparty',
    'description' => 'Description',
    'amount_native' => 'Amount (native)',
    'amount_settled' => 'Amount (settled)',
    'effective_rate' => 'Effective rate',
    'ics_markup' => 'Includes any ICS markup.',

    'split' => [
        'category' => 'Category',
        'open' => 'Split into categories',
        'heading' => 'Split across categories',
        'total' => 'Total :amount',
        'tax_per_category' => 'Tax tags are set per category below.',
        'choose_category' => 'Choose a category',
        'note_label' => 'Note',
        'note_placeholder' => 'Note (optional)',
        'tax_deductible' => 'Tax-deductible',
        'remove_leg_aria' => 'Remove this category',
        'remove_leg_caption' => 'Remove',
        'add_category' => '+ Add category',
        'soft_cap' => ':count of ~20 categories — consider grouping small amounts.',
        'remaining_zero' => 'Remaining :amount ✓',
        'remaining_to_assign' => 'Remaining to assign: :amount',
        'over_allocated' => 'Over-allocated by :amount — reduce a leg.',
        'save' => 'Save split',
        'saving' => 'Saving…',
        'unsplit' => 'Unsplit transaction',
        'remove_to_one' => 'Removing this leaves one category — the transaction becomes :category.',
        'remove_to_one_fallback' => 'this category',
        'remove_category' => 'Remove category',
        'keep_category' => 'Keep this category',
        'restore_single' => 'Restore as a single category?',
        'survivor_legend' => 'Category to keep',
        'confirm_unsplit' => 'Yes, unsplit',
        'keep_split' => 'Keep split',
    ],

    'tax' => [
        'section_aria' => 'Tax tag',
        'label' => 'Tax deductible',
    ],

    'reclassify' => [
        'heading' => 'Reclassify',
        'help' => 'Override the detected type. If this transaction is paired with another, choosing a non-transfer type will unpair both sides.',
        'choose_aria' => 'Choose new transaction type',
        'choose_option' => 'Choose a type…',
        'save' => 'Save',
    ],

    // Keyed by TransactionType's backing value: the reclassify dropdown offers
    // the enum's cases and the raw key is not a name anyone should have to read.
    'type_label' => [
        'expense' => 'Expense',
        'income' => 'Income',
        'transfer_out' => 'Transfer out',
        'transfer_in' => 'Transfer in',
        'fee' => 'Fee',
        'refund' => 'Refund',
        'adjustment' => 'Adjustment',
    ],

    'note' => [
        'heading' => 'Note',
        'help' => 'Personal note for this transaction. Visible only to you.',
        'label' => 'Note',
        'placeholder' => 'Add a note…',
        'save' => 'Save note',
        'saved' => 'Saved',
    ],

    'reassign' => [
        'heading' => 'Reassign counterparty',
        'help' => 'Override the resolved counterparty for this transaction.',
        'choose_aria' => 'Choose counterparty',
        'choose_option' => 'Choose a counterparty…',
        'submit' => 'Reassign',
    ],

    'goal' => [
        'heading' => 'Savings goal',
        'help' => 'Count this transaction toward one of your savings goals.',
        'choose_aria' => 'Choose a savings goal',
        'choose_option' => 'Choose a goal…',
        'submit' => 'Add to goal',
        'remove_aria' => 'Remove :name',
    ],

    'delete' => [
        'heading' => 'Delete transaction',
        'help' => 'Permanently removes this transaction. This action cannot be undone.',
        'button' => 'Delete',
        'confirm_prompt' => 'Delete this transaction? Its note, split and tax tags go with it.',
        'confirm' => 'Yes, delete',
        'cancel' => 'Cancel',
    ],

    'chain' => [
        'view' => 'View chain',
    ],

    'unreconcile' => [
        'heading' => 'Reconciled and locked',
        'help' => 'A completed reconcile locked this transaction. Its category, note, split and tax tags stay as they are until you unlock it.',
        'button' => 'Unlock for editing',
        'confirm_question' => 'Unlock this transaction for editing? Nothing on it changes, and completing the reconcile again locks it back.',
        'cancel' => 'Leave it locked',
    ],

    'toast' => [
        'reconciled_locked' => 'This transaction is reconciled. Un-reconcile it to make changes.',
        'reclassified_pair_removed' => 'Reclassified to :type — pair removed',
        'reclassified' => 'Reclassified to :type',
        'note_saved' => 'Note saved',
        'unreconciled' => 'Un-reconciled — you can edit this transaction again.',
        'note_too_long' => 'A note is at most :max character.|A note is at most :max characters.',
        'counterparty_updated' => 'Counterparty updated',
        'goal_attributed' => 'Counted toward this goal',
        'goal_attribution_removed' => 'No longer counted toward this goal',
        'split_saved' => 'Split saved',
        'removed_one_remains' => 'Removed — one category remains',
        'unsplit_restored' => 'Unsplit — restored to a single category',
    ],

    'errors' => [
        'totals_must_match' => "Couldn't save — leg totals must match the transaction total exactly.",
        'not_found' => 'Transaction not found.',
        'amount_zero' => "Amount can't be :amount",
        'choose_category' => 'Choose a category.',
        'choose_before_removing' => 'Choose a category before removing.',
        'choose_before_unsplitting' => 'Choose a category before unsplitting.',
        'not_found_or_unowned' => 'Transaction not found or not owned by user.',
        'reconciled_split' => 'This transaction is reconciled. Un-reconcile it to change its split.',
        'not_splittable' => "Transaction type ':type' is not splittable.",
        'min_two_legs' => 'A split requires at least 2 legs.',
        'legs_non_zero' => 'Leg amounts must be non-zero.',
        'legs_parent_sign' => "Leg amounts must share the parent's sign.",
        'leg_category_not_accessible' => 'Leg category not found or not accessible by user.',
        'survivor_not_accessible' => 'Surviving category not found or not accessible by user.',
        'survivor_must_be_current' => "Surviving category must be one of the split's current leg categories.",
    ],
];
