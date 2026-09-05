<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import finalizat',
        'receipts' => 'Bonuri noi găsite',
        'manual_entry' => 'Registrul de casă actualizat',
        'migration_finished' => 'Migrare finalizată',
        'drift' => 'O plată recurentă s-a schimbat',
        'forecast' => 'Urmează un deficit de lichiditate',
        'budget_nudge' => 'Bugetul este aproape epuizat',
        'budget_nudge_spent' => 'Bugetul este epuizat',
        'budget_nudge_over' => 'Bugetul este depășit',
        'savings_prompt' => 'Un loc unde ai putea economisi',
        'ics_statement_ready' => 'Extras ICS nou disponibil',
        'payment_reminder_confident' => 'Plată scadentă :day (:date)',
        'payment_reminder_hedged' => 'Plată scadentă în jurul zilei de :day (:date)',
        'position_digest_daily' => 'Situația ta zilnică',
        'position_digest_weekly' => 'Situația ta săptămânală',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent din :budget cheltuiți.',
        'receipts_matched' => ':count bon potrivit din e-mailul tău.|:count bonuri potrivite din e-mailul tău.|:count de bonuri potrivite din e-mailul tău.',
        'import_finished' => ':count tranzacție importată.|:count tranzacții importate.|:count de tranzacții importate.',
        'manual_entry' => ':count intrare adăugată manual.|:count intrări adăugate manual.|:count de intrări adăugate manual.',
        'migration_finished' => 'Bugetul tău a fost transferat, inclusiv :count tranzacție.|Bugetul tău a fost transferat, inclusiv :count tranzacții.|Bugetul tău a fost transferat, inclusiv :count de tranzacții.',
        'drift' => 'O plată recurentă a mers :direction cu :amount.',
        'forecast' => 'Soldul tău estimat scade sub zero pe :date.',
        'forecast_buffer' => 'Soldul tău estimat scade sub rezerva ta de :buffer pe :date.',
        'ics_statement_ready' => 'Descarcă-l din portalul ICS și trage-l în Beatrax ca să ții la zi cheltuielile acestui card.',
        'payment_reminder_hedged' => ':name — așteptată în jurul :day (:date), :amount.',
        'payment_reminder_confident' => ':name — scadentă :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'în sus',
        'down' => 'în jos',
    ],

    'digest' => [
        'nothing_notable' => 'Nimic nu îți cere atenția.',
        'flow' => 'Intrări :in, ieșiri :out, net :net.',
        'net_worth' => 'Valoare netă :amount.',
        'over_budget' => ':amount peste buget până acum.',
        'payments_due' => ':count plată scadentă în această perioadă.|:count plăți scadente în această perioadă.|:count de plăți scadente în această perioadă.',
        'shortfall' => 'Urmează un deficit de lichiditate.',
        'forecast_not_run' => 'Nu a rulat încă nicio prognoză de flux de numerar.',
    ],
];
