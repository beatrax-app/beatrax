<?php

declare(strict_types=1);

return [
    'page_title' => 'Pots · Beatrax',
    'heading' => 'Pots',
    'subtitle' => 'Virtual sub-balances that always add up to your real account balance.',
    'add_pot' => 'Add pot',

    'pot_fallback' => 'pot',

    'empty' => [
        'heading' => 'No pots yet',
        'body' => 'Create virtual sub-balances within any account to organise your money without a real bank transfer.',
        'cta' => 'Add your first pot',
        'no_accounts_cta' => 'Import a statement',
    ],

    'common' => [
        'cancel' => 'Cancel',
        'amount' => 'Amount',
        'note_optional' => 'Note (optional)',
    ],

    'actions' => [
        'fund' => 'Fund',
        'move' => 'Move',
        'edit' => 'Edit',
        'withdraw' => 'Withdraw',
        'archive' => 'Archive',
        'restore' => 'Restore',
    ],

    'recon' => [
        'over_allocated' => 'Pots exceed real balance by :amount — rebalance to fix',
        'real_balance' => 'Real balance:',
        'allocated' => 'Allocated:',
        'unallocated' => 'Unallocated:',
    ],

    'chip' => [
        'goal' => 'Goal:',
        'goal_name_fallback' => 'Goal',
        'category_fallback' => 'Category',
    ],

    'coverage' => [
        'spent' => 'spent',
        'in_pot' => 'in pot',
    ],

    'archive_confirm' => 'Archive this pot? Balance of :amount will return to unallocated.',
    'confirm_archive_aria' => 'Confirm archive of :name',
    'more_actions_aria' => 'More actions for :name',

    'history' => [
        'show' => 'Show history ↓',
        'hide' => 'Hide history ↑',
    ],

    'movement' => [
        'fund' => 'Fund',
        'withdraw' => 'Withdraw',
        'moved_from' => 'Moved from :name',
        'moved_to' => 'Moved to :name',
    ],

    'archived' => [
        'toggle' => 'Archived pots (:count)',
        'badge' => 'Archived',
    ],

    'form' => [
        'create_title' => 'Create a pot',
        'edit_title' => 'Edit pot',
        'create_subtitle' => 'Name a virtual sub-balance within an account.',
        'edit_subtitle' => 'Update the name or link for this pot.',
        'name' => 'Name',
        'name_placeholder' => 'e.g. Holiday fund',
        'account' => 'Account',
        'select_account' => 'Select an account',
        'initial_amount' => 'Initial amount (optional)',
        'initial_amount_help' => 'Amount is deducted from unallocated. Leave blank to create empty.',
        'link_to' => 'Link to (optional)',
        'link_goal' => 'Goal',
        'link_none' => 'None',
        'select_goal' => 'Select a goal',
        'save_pot' => 'Save pot',
        'save_changes' => 'Save changes',
    ],

    'fund' => [
        'title' => 'Fund pot',
        'heading' => 'Fund :name',
        'submit' => 'Fund pot',
        'note_placeholder' => 'e.g. Monthly savings',
        'available' => 'Available to allocate: :amount (unallocated)',
    ],

    'move' => [
        'title' => 'Move funds',
        'heading' => 'Move from :name',
        'to' => 'Move to',
        'select_pot' => 'Select a pot',
        'no_others_short' => 'No other pots',
        'no_others' => 'No other pots in this account',
        'submit' => 'Move funds',
        'note_placeholder' => 'e.g. Transfer for holiday',
    ],

    'withdraw' => [
        'heading' => 'Withdraw from :name',
        'note_placeholder' => 'e.g. Withdrawal',
    ],

    'available_in' => 'Available in :name: :amount',

    'errors' => [
        'enter_name' => 'Enter a name for this pot.',
        'select_account' => 'Select an account for this pot.',
        'amount_exceeds_unallocated' => 'Amount exceeds unallocated balance.',
        'amount_exceeds_unallocated_available' => 'Amount exceeds unallocated balance (:amount available).',
        'amount_exceeds_pot_balance' => 'Amount exceeds balance in :name (:amount available).',
        'generic' => 'That pot could not be saved. Check the fields and try again.',
        'amount_invalid' => 'Enter an amount greater than zero.',
        'goal_already_linked' => 'This goal already has an active linked pot. Archive it first.',
    ],

    'toast' => [
        'pot_created' => 'Pot created.',
        'pot_updated' => 'Pot updated.',
        'pot_funded' => 'Pot funded.',
        'withdrawn' => 'Withdrawn from pot.',
        'funds_moved' => 'Funds moved.',
        'pot_archived' => 'Pot archived.',
        'pot_restored' => 'Pot restored.',
    ],
];
