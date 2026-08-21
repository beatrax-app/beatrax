<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importen er færdig',
        'receipts' => 'Nye kvitteringer fundet',
        'drift' => 'En tilbagevendende postering er ændret',
        'forecast' => 'Underskud i pengestrømmen forude',
        'budget_nudge' => 'Budgettet er næsten brugt',
        'savings_prompt' => 'Der findes et billigere abonnement',
        'ics_statement_ready' => 'Nyt ICS-kontoudtog klar',
        'payment_reminder_confident' => 'Betaling forfalder :day',
        'payment_reminder_hedged' => 'Betaling forfalder omkring :day',
        'position_digest_daily' => 'Din daglige status',
        'position_digest_weekly' => 'Din ugentlige status',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent af :budget brugt.',
        'receipts_matched' => ':count kvittering matchet fra din e-mail.|:count kvitteringer matchet fra din e-mail.',
        'import_finished' => ':count transaktion importeret.|:count transaktioner importeret.',
        'drift' => 'En tilbagevendende postering gik :direction med :amount.',
        'forecast' => 'Din forventede saldo falder under nul inden for de næste 30 dage.',
        'ics_statement_ready' => 'Hent det fra ICS-portalen, og læg det ind i Beatrax for at holde kortets forbrug opdateret.',
        'payment_reminder_hedged' => ':name — forventet omkring :day, :amount.',
        'payment_reminder_confident' => ':name — forfalder :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/md.)',
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
