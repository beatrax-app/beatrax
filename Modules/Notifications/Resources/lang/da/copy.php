<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importen er færdig',
        'receipts' => 'Nye kvitteringer fundet',
        'manual_entry' => 'Kassebogen er opdateret',
        'migration_finished' => 'Migreringen er færdig',
        'drift' => 'En tilbagevendende postering er ændret',
        'forecast' => 'Underskud i pengestrømmen forude',
        'budget_nudge' => 'Budgettet er næsten brugt',
        'budget_nudge_spent' => 'Budgettet er brugt',
        'budget_nudge_over' => 'Budgettet er overskredet',
        'savings_prompt' => 'Et sted du kan spare',
        'ics_statement_ready' => 'Nyt ICS-kontoudtog klar',
        'payment_reminder_confident' => 'Betaling forfalder :day (:date)',
        'payment_reminder_hedged' => 'Betaling forfalder omkring :day (:date)',
        'position_digest_daily' => 'Din daglige status',
        'position_digest_weekly' => 'Din ugentlige status',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent af :budget brugt.',
        'receipts_matched' => ':count kvittering matchet fra din e-mail.|:count kvitteringer matchet fra din e-mail.',
        'import_finished' => ':count transaktion importeret.|:count transaktioner importeret.',
        'manual_entry' => ':count post tilføjet manuelt.|:count poster tilføjet manuelt.',
        'migration_finished' => 'Dit budget er flyttet med, inklusive :count transaktion.|Dit budget er flyttet med, inklusive :count transaktioner.',
        'drift' => 'En tilbagevendende postering gik :direction med :amount.',
        'forecast' => 'Din forventede saldo falder under nul den :date.',
        'forecast_buffer' => 'Din forventede saldo falder under din buffer på :buffer den :date.',
        'ics_statement_ready' => 'Hent det fra ICS-portalen, og læg det ind i Beatrax for at holde kortets forbrug opdateret.',
        'payment_reminder_hedged' => ':name — forventet omkring :day (:date), :amount.',
        'payment_reminder_confident' => ':name — forfalder :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'op',
        'down' => 'ned',
    ],

    'digest' => [
        'nothing_notable' => 'Intet kræver din opmærksomhed.',
        'flow' => 'Ind :in, ud :out, netto :net.',
        'over_budget' => ':amount over budget indtil videre.',
        'payments_due' => ':count betaling forfalder i denne periode.|:count betalinger forfalder i denne periode.',
        'shortfall' => 'Der venter et underskud i pengestrømmen.',
    ],
];
