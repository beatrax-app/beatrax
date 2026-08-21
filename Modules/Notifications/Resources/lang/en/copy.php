<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import finished',
        'receipts' => 'New receipts found',
        'drift' => 'A recurring charge changed',
        'forecast' => 'Cash-flow shortfall ahead',
        'budget_nudge' => 'Budget nearly spent',
        'savings_prompt' => 'A cheaper plan exists',
        'ics_statement_ready' => 'New ICS statement ready',
        'payment_reminder_confident' => 'Payment due :day',
        'payment_reminder_hedged' => 'Payment due around :day',
        'position_digest_daily' => 'Your daily position',
        'position_digest_weekly' => 'Your weekly position',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent of :budget spent.',
        'receipts_matched' => ':count receipt matched from your email.|:count receipts matched from your email.',
        'import_finished' => ':count transaction imported.|:count transactions imported.',
        'drift' => 'A recurring charge moved :direction by :delta :currency.',
        'forecast' => 'Your projected balance dips below zero within the next 30 days.',
        'ics_statement_ready' => "Download it from the ICS portal and drop it into Beatrax to keep this card's spending up to date.",
        'payment_reminder_hedged' => ':name — expected around :day, :amount.',
        'payment_reminder_confident' => ':name — due :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mo)',
    ],

    'drift_direction' => [
        'up' => 'up',
        'down' => 'down',
    ],

    'digest' => [
        'nothing_notable' => 'Nothing needs your attention.',
        'flow' => 'In :in, out :out, net :net.',
        'over_budget' => ':amount over budget so far.',
        'payments_due' => '1 payment due this period.|:count payments due this period.',
        'shortfall' => 'A cash-flow shortfall is ahead.',
    ],
];
