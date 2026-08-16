<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importen er ferdig',
        'receipts' => 'Nye kvitteringer funnet',
        'drift' => 'En gjentakende belastning er endret',
        'forecast' => 'Underskudd i kontantstrømmen fremover',
        'budget_nudge' => 'Budsjettet er nesten brukt opp',
        'savings_prompt' => 'Det finnes et billigere abonnement',
        'ics_statement_ready' => 'Ny ICS-kontoutskrift klar',
        'payment_reminder_confident' => 'Betaling forfaller :day',
        'payment_reminder_hedged' => 'Betaling forfaller rundt :day',
        'position_digest_daily' => 'Din daglige status',
        'position_digest_weekly' => 'Din ukentlige status',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent av :budget brukt.',
        'receipts_matched' => ':count kvittering matchet fra e-posten din.|:count kvitteringer matchet fra e-posten din.',
        'import_finished' => ':count transaksjon importert.|:count transaksjoner importert.',
        'drift' => 'En gjentakende belastning gikk :direction med :delta :currency.',
        'forecast' => 'Den forventede saldoen din faller under null i løpet av de neste 30 dagene.',
        'ics_statement_ready' => 'Last det ned fra ICS-portalen og legg det inn i Beatrax for å holde forbruket på dette kortet oppdatert.',
        'payment_reminder_hedged' => ':name — ventet rundt :day, :amount.',
        'payment_reminder_confident' => ':name — forfaller :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mnd.)',
    ],

    'drift_direction' => [
        'up' => 'opp',
        'down' => 'ned',
    ],

    'digest' => [
        'nothing_notable' => 'Ingenting krever oppmerksomheten din.',
        'flow' => 'Inn :in, ut :out, netto :net.',
        'over_budget' => ':amount over budsjett så langt.',
        'payments_due' => '1 betaling forfaller denne perioden.|:count betalinger forfaller denne perioden.',
        'shortfall' => 'Det venter et underskudd i kontantstrømmen.',
    ],
];
