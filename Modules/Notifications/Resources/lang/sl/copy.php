<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Uvoz končan',
        'receipts' => 'Najdena so nova potrdila',
        'drift' => 'Ponavljajoča bremenitev se je spremenila',
        'forecast' => 'Pred tabo je primanjkljaj sredstev',
        'budget_nudge' => 'Proračun je skoraj porabljen',
        'savings_prompt' => 'Obstaja cenejši paket',
        'ics_statement_ready' => 'Nov izpisek ICS je pripravljen',
        'payment_reminder_confident' => 'Plačilo zapade :day',
        'payment_reminder_hedged' => 'Plačilo zapade okrog :day',
        'position_digest_daily' => 'Tvoje dnevno stanje',
        'position_digest_weekly' => 'Tvoje tedensko stanje',
    ],

    'body' => [
        'budget_nudge' => ':category — porabljeno :spent od :budget.',
        'receipts_matched' => 'Iz tvoje e-pošte je povezano :count potrdilo.|Iz tvoje e-pošte sta povezani :count potrdili.|Iz tvoje e-pošte so povezana :count potrdila.|Iz tvoje e-pošte je povezanih :count potrdil.',
        'import_finished' => 'Uvožena je :count transakcija.|Uvoženi sta :count transakciji.|Uvožene so :count transakcije.|Uvoženih je :count transakcij.',
        'drift' => 'Ponavljajoča bremenitev se je premaknila :direction za :delta :currency.',
        'forecast' => 'Tvoje predvideno stanje v naslednjih 30 dneh pade pod nič.',
        'ics_statement_ready' => 'Prenesi ga s portala ICS in ga odloži v Beatrax, da bo poraba te kartice ostala ažurna.',
        'payment_reminder_hedged' => ':name — pričakovano okrog :day, :amount.',
        'payment_reminder_confident' => ':name — zapade :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mes.)',
    ],

    'drift_direction' => [
        'up' => 'navzgor',
        'down' => 'navzdol',
    ],

    'digest' => [
        'nothing_notable' => 'Nič ne potrebuje tvoje pozornosti.',
        'flow' => 'Priliv :in, odliv :out, neto :net.',
        'over_budget' => 'Doslej :amount nad proračunom.',
        'payments_due' => ':count plačilo zapade v tem obdobju.|:count plačili zapadeta v tem obdobju.|:count plačila zapadejo v tem obdobju.|:count plačil zapade v tem obdobju.',
        'shortfall' => 'Pred tabo je primanjkljaj sredstev.',
    ],
];
