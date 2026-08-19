<?php

declare(strict_types=1);

return [
    'page_title' => 'Counterparty triage',
    'heading' => 'Triage unknown counterparties',

    'progress' => ':seen of :total · :percent % · ~:minutes min remaining',
    'progress_aria' => 'Triage progress',

    'all_caught_aria' => 'All counterparties labeled',
    'all_caught_heading' => '🎉 All caught up — every counterparty is labeled.',
    'back_to_index' => 'Back to counterparties →',

    'meta' => ':count transaction · last seen :date|:count transactions · last seen :date',

    'suggested_aria' => 'Suggested match',
    'suggestion_medium' => '✨ Maybe **:name** — confidence medium',
    'suggestion_low' => 'Pattern match: **:name** — confidence low. Verify before linking.',
    'suggestion_high' => '✨ Looks like **:name** — confidence high',

    'reasoning' => ':hits of :total recent transactions on this IBAN resolve to :name.',
    'yes_link' => 'Yes, link to :name ↵',
    'no_not' => 'No, not :name',

    'recent_on_iban' => 'Recent transactions on this IBAN',
    'no_transactions_yet' => 'No transactions on file yet.',

    'label_manually' => 'Or label manually',
    'display_name_label' => 'Display name',
    'display_name_placeholder' => 'Display name…',
    'type_label' => 'Type',
    'type_merchant' => 'Merchant',
    'type_personal' => 'Personal',
    'type_bank' => 'Bank',
    'type_government' => 'Government',
    'save_label' => 'Save label',

    'skip' => 'Skip for now',
    'mark_ignored' => 'Mark as ignored',
    'previous' => 'Previous unknown',
    'next' => 'Next',

    'kbd_yes' => 'yes',
    'kbd_no' => 'no',
    'kbd_skip' => 'skip',
    'kbd_next' => 'next',

    'footer' => ':seen already labeled · :count to go',
];
