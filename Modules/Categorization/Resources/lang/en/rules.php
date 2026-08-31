<?php

declare(strict_types=1);

return [
    'page_title' => 'Rules',
    'heading' => 'Rules',
    'intro' => 'Pre-categorize transactions on import. Rules apply to every source — bank, card, PayPal, and email receipts.',
    'device_local_note' => 'Rules stay on this device. They are not shared with your other devices.',

    'reapply' => 'Re-apply rules to history',
    'reapply_confirm' => 'Re-apply every rule to your whole history? Every category, counterparty, note and tax tag a rule put there is rewritten. What you set by hand stays, and so does anything on a reconciled statement. Nothing puts the old values back.',
    'reapplying' => 'Re-applying…',
    'new_rule' => 'New rule',

    'reapply_progress' => 'Re-applying rules… :checked of :count transaction checked|Re-applying rules… :checked of :count transactions checked',

    'empty_heading' => 'No rules yet',
    'empty_body' => 'Rules match transactions on multiple conditions and apply category, counterparty, note, and tax-tag changes automatically — on import, and any time you re-apply them to your existing history.',
    'empty_cta' => 'Create your first rule',

    'col_priority' => 'Priority',
    'col_conditions' => 'Conditions',
    'col_actions' => 'Actions',
    'col_hits' => 'Hits',
    'col_created' => 'Created',
    'col_row_actions' => 'Actions',
    'inactive_badge' => 'Off',
    'combinator_all' => 'ALL',
    'combinator_any' => 'ANY',
    'inactive_title' => 'This rule does not run. A rule switches off when the category or counterparty it points at is deleted.',

    'more_conditions' => '+:count more',

    'delete_confirm' => 'Delete?',
    'delete_yes' => 'Yes, delete',
    'cancel' => 'Cancel',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'edit_aria' => 'Edit rule (priority :priority)',
    'delete_aria' => 'Delete rule (priority :priority)',

    'footer_note' => 'Rules and merchant history work together. Deleting a rule doesn\'t clear what Beatrax has learned from past categorizations — the next import may still auto-suggest the same category from history.',

    'chip_category' => 'Category: :path',
    'chip_counterparty' => 'Counterparty: :path',
    'chip_note' => 'Note',
    'chip_tax_tag' => 'Tax tag',

    'flash_deleted' => 'Rule deleted.',
    'flash_not_found' => 'Rule not found (it may have been deleted in another tab).',
    'flash_saved' => 'Rule saved.',
    'flash_reapplying' => 'Re-applying rules to your history…',
    'summary_no_changes' => 'No changes — your history already matches your rules.',
    'summary_updated' => 'Updated :fields across :transactions.',
    'summary_fields' => ':count field|:count fields',
    'summary_transactions' => ':count transaction|:count transactions',
    'summary_reconciled_skipped' => ':count reconciled transaction was skipped.|:count reconciled transactions were skipped.',
];
