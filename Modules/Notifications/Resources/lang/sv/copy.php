<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importen är klar',
        'receipts' => 'Nya kvitton hittade',
        'manual_entry' => 'Kassaboken är uppdaterad',
        'migration_finished' => 'Migreringen är klar',
        'drift' => 'En återkommande debitering har ändrats',
        'forecast' => 'Underskott i kassaflödet framöver',
        'budget_nudge' => 'Budgeten är nästan slut',
        'budget_nudge_spent' => 'Budgeten är slut',
        'budget_nudge_over' => 'Budgeten är överskriden',
        'savings_prompt' => 'Ett ställe där du kan spara',
        'ics_statement_ready' => 'Nytt ICS-kontoutdrag klart',
        'payment_reminder_confident' => 'Betalning förfaller :day (:date)',
        'payment_reminder_hedged' => 'Betalning förfaller omkring :day (:date)',
        'position_digest_daily' => 'Din dagliga ställning',
        'position_digest_weekly' => 'Din veckovisa ställning',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent av :budget använt.',
        'receipts_matched' => ':count kvitto matchat från din e-post.|:count kvitton matchade från din e-post.',
        'import_finished' => ':count transaktion importerad.|:count transaktioner importerade.',
        'manual_entry' => ':count post tillagd för hand.|:count poster tillagda för hand.',
        'migration_finished' => 'Din budget är överflyttad, inklusive :count transaktion.|Din budget är överflyttad, inklusive :count transaktioner.',
        'drift' => 'En återkommande debitering gick :direction med :amount.',
        'forecast' => 'Ditt prognostiserade saldo sjunker under noll den :date.',
        'forecast_buffer' => 'Ditt prognostiserade saldo sjunker under din buffert på :buffer den :date.',
        'ics_statement_ready' => 'Ladda ner det från ICS-portalen och lägg in det i Beatrax för att hålla kortets utgifter uppdaterade.',
        'payment_reminder_hedged' => ':name — väntas omkring :day (:date), :amount.',
        'payment_reminder_confident' => ':name — förfaller :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'upp',
        'down' => 'ner',
    ],

    'digest' => [
        'nothing_notable' => 'Inget kräver din uppmärksamhet.',
        'flow' => 'In :in, ut :out, netto :net.',
        'net_worth' => 'Nettoförmögenhet :amount.',
        'over_budget' => ':amount över budget hittills.',
        'payments_due' => ':count betalning förfaller den här perioden.|:count betalningar förfaller den här perioden.',
        'shortfall' => 'Ett underskott i kassaflödet väntar.',
        'forecast_not_run' => 'Ingen kassaflödesprognos har körts ännu.',
    ],
];
