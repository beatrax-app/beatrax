<?php

declare(strict_types=1);

return [
    'page_title' => 'Reconciliere',
    'heading' => 'Reconciliere',
    'intro' => 'Confirmă soldul din extrasul unui cont față de tranzacțiile tale decontate. Când se potrivesc, finalizează reconcilierea pentru a bloca acele rânduri pe loc.',

    'account' => 'Cont',
    'choose_account' => 'Alege un cont…',
    'statement_date' => 'Data extrasului',
    'statement_balance' => 'Sold din extras (€)',
    'balance_help' => 'Precompletat din ultimul extras de cont importat, când există — negativ pentru bani datorați, editabil în ambele cazuri.',

    'cleared_balance' => 'Sold decontat',
    'statement_target' => 'Ținta din extras',
    'difference' => 'Diferență',

    'pill' => [
        'choose_account' => 'alege un cont',
        'enter_balance' => 'introdu un sold din extras',
        'matched' => 'se potrivește — :amount',
        'discrepancy' => 'discrepanță — :amount',
    ],

    'mismatch_html' => 'Soldul din extras încă nu se potrivește cu soldul tău decontat. Comută rândurile decontate în <a href=":url" class="underline">lista de tranzacții</a> sau ajustează soldul introdus până când diferența ajunge la zero — acest flux nu creează niciodată o înregistrare de echilibrare.',

    'check' => 'Verifică',
    'complete' => 'Finalizează reconcilierea',

    'errors' => [
        'choose_account' => 'Alege mai întâi un cont.',
        'invalid_balance_date' => 'Introdu un sold din extras și o dată valide.',
        'mismatch' => 'Soldul din extras încă nu se potrivește cu soldul decontat — ajustează rândurile decontate sau soldul introdus până când diferența este zero.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Nu există nimic de blocat pentru această dată a extrasului.',
        'complete' => 'Reconciliere finalizată — :count rânduri blocate.',
    ],
];
