<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Uvoz končan',
        'receipts' => 'Najdena so nova potrdila',
        'manual_entry' => 'Blagajniška knjiga posodobljena',
        'migration_finished' => 'Migracija končana',
        'drift' => 'Ponavljajoča bremenitev se je spremenila',
        'forecast' => 'Pred tabo je primanjkljaj sredstev',
        'budget_nudge' => 'Proračun je skoraj porabljen',
        'budget_nudge_spent' => 'Proračun je porabljen',
        'budget_nudge_over' => 'Proračun je presežen',
        'savings_prompt' => 'Mesto, kjer bi lahko prihranil',
        'ics_statement_ready' => 'Nov izpisek ICS je pripravljen',
        'payment_reminder_confident' => 'Plačilo zapade :day (:date)',
        'payment_reminder_hedged' => 'Plačilo zapade okrog :day (:date)',
        'position_digest_daily' => 'Tvoje dnevno stanje',
        'position_digest_weekly' => 'Tvoje tedensko stanje',
    ],

    'body' => [
        'budget_nudge' => ':category — porabljeno :spent od :budget.',
        'receipts_matched' => 'Iz tvoje e-pošte je povezano :count potrdilo.|Iz tvoje e-pošte sta povezani :count potrdili.|Iz tvoje e-pošte so povezana :count potrdila.|Iz tvoje e-pošte je povezanih :count potrdil.',
        'import_finished' => 'Uvožena je :count transakcija.|Uvoženi sta :count transakciji.|Uvožene so :count transakcije.|Uvoženih je :count transakcij.',
        'manual_entry' => 'Ročno je dodan :count vnos.|Ročno sta dodana :count vnosa.|Ročno so dodani :count vnosi.|Ročno je dodanih :count vnosov.',
        'migration_finished' => 'Tvoj proračun je prenesen, vključno z :count transakcijo.|Tvoj proračun je prenesen, vključno z :count transakcijama.|Tvoj proračun je prenesen, vključno z :count transakcijami.|Tvoj proračun je prenesen, vključno z :count transakcijami.',
        'drift' => 'Ponavljajoča bremenitev se je premaknila :direction za :amount.',
        'forecast' => 'Tvoje predvideno stanje :date pade pod nič.',
        'forecast_buffer' => 'Tvoje predvideno stanje :date pade pod tvojo rezervo :buffer.',
        'ics_statement_ready' => 'Prenesi ga s portala ICS in ga odloži v Beatrax, da bo poraba te kartice ostala ažurna.',
        'payment_reminder_hedged' => ':name — pričakovano okrog :day (:date), :amount.',
        'payment_reminder_confident' => ':name — zapade :day (:date), :amount.',
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
