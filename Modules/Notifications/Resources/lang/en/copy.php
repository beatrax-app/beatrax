<?php

declare(strict_types=1);

return [
    // Titles. import_finished/receipts/drift/forecast/budget_nudge/
    // savings_prompt/ics_statement_ready are LOCKED — their English wording is
    // copied byte-for-byte from the desktop OS-notification adapter's original
    // constants and must not be reworded without a UI-SPEC amendment.
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

    // Bodies. Interpolated values (amounts, names, dates, day names) are
    // formatted by the caller and passed through as placeholders; only the
    // surrounding wording is translated here.
    'body' => [
        'budget_nudge' => ':category — :spent of :budget spent.',
        'receipts_matched_one' => ':count receipt matched from your email.',
        'receipts_matched_other' => ':count receipts matched from your email.',
        'import_finished_one' => ':count transaction imported.',
        'import_finished_other' => ':count transactions imported.',
        'drift' => 'A recurring charge moved :direction by :delta :currency.',
        'forecast' => 'Your projected balance dips below zero within the next 30 days.',
        'ics_statement_ready' => "Download it from the ICS portal and drop it into beatrax to keep this card's spending up to date.",
        'payment_reminder_hedged' => ':name — expected around :day, :amount.',
        'payment_reminder_confident' => ':name — due :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mo)',
    ],

    // The direction word interpolated into the drift body, translated so the
    // Dutch sentence reads "omhoog"/"omlaag" rather than a stray English word.
    'drift_direction' => [
        'up' => 'up',
        'down' => 'down',
    ],

    // The weekly/daily position digest is assembled from these parts, joined
    // by spaces; only the parts that apply to the user's period are included.
    'digest' => [
        'nothing_notable' => 'Nothing needs your attention.',
        'flow' => 'In :in, out :out, net :net.',
        'over_budget' => ':amount over budget so far.',
        'payments_due_one' => '1 payment due this period.',
        'payments_due_other' => ':count payments due this period.',
        'shortfall' => 'A cash-flow shortfall is ahead.',
    ],
];
