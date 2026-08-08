<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Drop an email message (.eml) or a mailbox archive (.mbox). The matcher recognises PayPal receipts and surfaces them as canonical transactions; unmatched senders stay in the audit log for triage.',
    ],

    'conflict' => [

        'field' => [
            'amount_minor' => 'amount',
            'currency' => 'currency',
            'description' => 'description',
            'counterparty_name' => 'merchant name',
            'default' => 'value',
        ],
        'heading_cleaner' => 'An email receipt has a cleaner :field',
        'heading_different' => 'An email receipt records a different :field',
        'title' => 'Receipt and statement disagree.',
        'body' => ':heading (“:receipt”) than the statement (“:statement”). Should Beatrax prefer receipts for future conflicts?',
        'use_receipt' => 'Use receipt',
        'keep_statement' => 'Keep statement',
    ],
];
