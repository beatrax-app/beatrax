<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importen er ferdig',
        'receipts' => 'Nye kvitteringer funnet',
        'manual_entry' => 'Kasseboken er oppdatert',
        'migration_finished' => 'Migreringen er ferdig',
        'drift' => 'En gjentakende belastning er endret',
        'forecast' => 'Underskudd i kontantstrømmen fremover',
        'budget_nudge' => 'Budsjettet er nesten brukt opp',
        'budget_nudge_spent' => 'Budsjettet er brukt opp',
        'budget_nudge_over' => 'Budsjettet er overskredet',
        'savings_prompt' => 'Et sted du kan spare',
        'ics_statement_ready' => 'Ny ICS-kontoutskrift klar',
        'payment_reminder_confident' => 'Betaling forfaller :day (:date)',
        'payment_reminder_hedged' => 'Betaling forfaller rundt :day (:date)',
        'position_digest_daily' => 'Din daglige status',
        'position_digest_weekly' => 'Din ukentlige status',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent av :budget brukt.',
        'receipts_matched' => ':count kvittering matchet fra e-posten din.|:count kvitteringer matchet fra e-posten din.',
        'import_finished' => ':count transaksjon importert.|:count transaksjoner importert.',
        'manual_entry' => ':count oppføring lagt inn manuelt.|:count oppføringer lagt inn manuelt.',
        'migration_finished' => 'Budsjettet ditt er flyttet over, inkludert :count transaksjon.|Budsjettet ditt er flyttet over, inkludert :count transaksjoner.',
        'drift' => 'En gjentakende belastning gikk :direction med :amount.',
        'forecast' => 'Den forventede saldoen din faller under null :date.',
        'forecast_buffer' => 'Den forventede saldoen din faller under bufferen din på :buffer :date.',
        'ics_statement_ready' => 'Last det ned fra ICS-portalen og legg det inn i Beatrax for å holde forbruket på dette kortet oppdatert.',
        'payment_reminder_hedged' => ':name — ventet rundt :day (:date), :amount.',
        'payment_reminder_confident' => ':name — forfaller :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'opp',
        'down' => 'ned',
    ],

    'digest' => [
        'nothing_notable' => 'Ingenting krever oppmerksomheten din.',
        'flow' => 'Inn :in, ut :out, netto :net.',
        'net_worth' => 'Nettoformue :amount.',
        'over_budget' => ':amount over budsjett så langt.',
        'payments_due' => ':count betaling forfaller denne perioden.|:count betalinger forfaller denne perioden.',
        'shortfall' => 'Det venter et underskudd i kontantstrømmen.',
        'forecast_not_run' => 'Ingen kontantstrømprognose har kjørt ennå.',
    ],
];
