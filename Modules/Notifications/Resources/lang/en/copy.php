<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import finished',
        'receipts' => 'New receipts found',
        'manual_entry' => 'Cash book updated',
        'migration_finished' => 'Migration finished',
        'drift' => 'A recurring charge changed',
        'forecast' => 'Cash-flow shortfall ahead',
        'budget_nudge' => 'Budget nearly spent',
        'budget_nudge_spent' => 'Budget fully spent',
        'budget_nudge_over' => 'Budget overspent',
        'savings_prompt' => 'A place you could save',
        'ics_statement_ready' => 'New ICS statement ready',
        'payment_reminder_confident' => 'Payment due :day (:date)',
        'payment_reminder_hedged' => 'Payment due around :day (:date)',
        'position_digest_daily' => 'Your daily position',
        'position_digest_weekly' => 'Your weekly position',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent of :budget spent.',
        'receipts_matched' => ':count receipt matched from your email.|:count receipts matched from your email.',
        'import_finished' => ':count transaction imported.|:count transactions imported.',
        'manual_entry' => ':count entry added by hand.|:count entries added by hand.',
        'migration_finished' => 'Your budget moved over, including :count transaction.|Your budget moved over, including :count transactions.',
        'drift' => 'A recurring charge moved :direction by :amount.',
        'forecast' => 'Your projected balance dips below zero on :date.',
        'forecast_buffer' => 'Your projected balance dips below your :buffer buffer on :date.',
        'ics_statement_ready' => "Download it from the ICS portal and drop it into Beatrax to keep this card's spending up to date.",
        'payment_reminder_hedged' => ':name — expected around :day (:date), :amount.',
        'payment_reminder_confident' => ':name — due :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'up',
        'down' => 'down',
    ],

    'digest' => [
        'nothing_notable' => 'Nothing needs your attention.',
        'flow' => 'In :in, out :out, net :net.',
        'over_budget' => ':amount over budget so far.',
        'payments_due' => ':count payment due this period.|:count payments due this period.',
        'shortfall' => 'A cash-flow shortfall is ahead.',
    ],
];
