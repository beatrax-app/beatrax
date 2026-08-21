<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importen är klar',
        'receipts' => 'Nya kvitton hittade',
        'drift' => 'En återkommande debitering har ändrats',
        'forecast' => 'Underskott i kassaflödet framöver',
        'budget_nudge' => 'Budgeten är nästan slut',
        'savings_prompt' => 'Det finns ett billigare abonnemang',
        'ics_statement_ready' => 'Nytt ICS-kontoutdrag klart',
        'payment_reminder_confident' => 'Betalning förfaller :day',
        'payment_reminder_hedged' => 'Betalning förfaller omkring :day',
        'position_digest_daily' => 'Din dagliga ställning',
        'position_digest_weekly' => 'Din veckovisa ställning',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent av :budget använt.',
        'receipts_matched' => ':count kvitto matchat från din e-post.|:count kvitton matchade från din e-post.',
        'import_finished' => ':count transaktion importerad.|:count transaktioner importerade.',
        'drift' => 'En återkommande debitering gick :direction med :amount.',
        'forecast' => 'Ditt prognostiserade saldo sjunker under noll inom de närmaste 30 dagarna.',
        'ics_statement_ready' => 'Ladda ner det från ICS-portalen och lägg in det i Beatrax för att hålla kortets utgifter uppdaterade.',
        'payment_reminder_hedged' => ':name — väntas omkring :day, :amount.',
        'payment_reminder_confident' => ':name — förfaller :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mån)',
    ],

    'drift_direction' => [
        'up' => 'upp',
        'down' => 'ner',
    ],

    'digest' => [
        'nothing_notable' => 'Inget kräver din uppmärksamhet.',
        'flow' => 'In :in, ut :out, netto :net.',
        'over_budget' => ':amount över budget hittills.',
        'payments_due' => ':count betalning förfaller den här perioden.|:count betalningar förfaller den här perioden.',
        'shortfall' => 'Ett underskott i kassaflödet väntar.',
    ],
];
