<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import finalizat',
        'receipts' => 'Bonuri noi găsite',
        'drift' => 'O plată recurentă s-a schimbat',
        'forecast' => 'Urmează un deficit de lichiditate',
        'budget_nudge' => 'Bugetul este aproape epuizat',
        'savings_prompt' => 'Există un plan mai ieftin',
        'ics_statement_ready' => 'Extras ICS nou disponibil',
        'payment_reminder_confident' => 'Plată scadentă :day',
        'payment_reminder_hedged' => 'Plată scadentă în jurul zilei de :day',
        'position_digest_daily' => 'Situația ta zilnică',
        'position_digest_weekly' => 'Situația ta săptămânală',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent din :budget cheltuiți.',
        'receipts_matched' => ':count bon potrivit din e-mailul tău.|:count bonuri potrivite din e-mailul tău.|:count de bonuri potrivite din e-mailul tău.',
        'import_finished' => ':count tranzacție importată.|:count tranzacții importate.|:count de tranzacții importate.',
        'drift' => 'O plată recurentă a mers :direction cu :delta :currency.',
        'forecast' => 'Soldul tău estimat scade sub zero în următoarele 30 de zile.',
        'ics_statement_ready' => 'Descarcă-l din portalul ICS și trage-l în Beatrax ca să ții la zi cheltuielile acestui card.',
        'payment_reminder_hedged' => ':name — așteptată în jurul :day, :amount.',
        'payment_reminder_confident' => ':name — scadentă :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/lună)',
    ],

    'drift_direction' => [
        'up' => 'în sus',
        'down' => 'în jos',
    ],

    'digest' => [
        'nothing_notable' => 'Nimic nu îți cere atenția.',
        'flow' => 'Intrări :in, ieșiri :out, net :net.',
        'over_budget' => ':amount peste buget până acum.',
        'payments_due' => '1 plată scadentă în această perioadă.|:count plăți scadente în această perioadă.|:count de plăți scadente în această perioadă.',
        'shortfall' => 'Urmează un deficit de lichiditate.',
    ],
];
