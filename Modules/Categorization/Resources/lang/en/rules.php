<?php

declare(strict_types=1);

return [
    'page_title' => 'Rules',
    'heading' => 'Rules',
    'intro' => 'Pre-categorize transactions on import. Rules apply to every source — bank, card, PayPal, and email receipts.',

    'reapply' => 'Re-apply rules to history',
    'reapplying' => 'Re-applying…',
    'new_rule' => 'New rule',

    'reapply_progress_lead' => 'Re-applying rules…',
    'reapply_progress_of' => 'of',
    'reapply_progress_trail' => 'transactions checked',

    'empty_heading' => 'No rules yet',
    'empty_body' => 'Rules match transactions on multiple conditions and apply category, counterparty, note, and tax-tag changes automatically — on import, and any time you re-apply them to your existing history.',
    'empty_cta' => 'Create your first rule',

    'col_priority' => 'Priority',
    'col_conditions' => 'Conditions',
    'col_actions' => 'Actions',
    'col_hits' => 'Hits',
    'col_created' => 'Created',
    'col_row_actions' => 'Actions',

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
    'summary_updated' => 'Updated :fields fields across :transactions transactions.',
    'summary_reconciled_skipped' => ':count reconciled transactions were skipped.',
];
