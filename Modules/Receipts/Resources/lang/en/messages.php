<?php

declare(strict_types=1);

return [
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
        'heading_restated' => 'A later statement records a different :field',
        'restated_title' => 'Your bank restated this transaction.',
        'restated_body' => ':heading (“:incoming”) than the row already stored (“:stored”). Should Beatrax prefer the incoming value for future disagreements?',
        'use_restated' => 'Use the new value',
        'keep_stored' => 'Keep the stored value',
    ],
];
