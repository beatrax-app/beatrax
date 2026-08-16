<?php

declare(strict_types=1);

return [
    'page_title' => 'Riconcilia',
    'heading' => 'Riconcilia',
    'intro' => "Confronta il saldo dell'estratto conto di un conto con le tue transazioni compensate. Quando coincidono, completa la riconciliazione per bloccare quelle righe.",

    'account' => 'Conto',
    'choose_account' => 'Scegli un conto…',
    'statement_date' => 'Data estratto conto',
    'statement_balance' => 'Saldo estratto conto (€)',
    'balance_help' => 'Precompilato dal tuo ultimo estratto conto importato quando disponibile — negativo per il denaro dovuto, comunque modificabile.',

    'cleared_balance' => 'Saldo compensato',
    'statement_target' => 'Obiettivo estratto conto',
    'difference' => 'Differenza',

    'pill' => [
        'choose_account' => 'scegli un conto',
        'enter_balance' => 'inserisci un saldo estratto conto',
        'matched' => 'coincide — :amount',
        'discrepancy' => 'discrepanza — :amount',
    ],

    'mismatch_html' => 'Il saldo del tuo estratto conto non corrisponde ancora al saldo compensato. Cambia lo stato delle righe compensate nella <a href=":url" class="underline">lista delle transazioni</a> oppure modifica il saldo inserito finché la differenza raggiunge zero — questo flusso non crea mai una scrittura di pareggio.',

    'check' => 'Verifica',
    'complete' => 'Completa la riconciliazione',

    'errors' => [
        'choose_account' => 'Scegli prima un conto.',
        'invalid_balance_date' => "Inserisci un saldo e una data dell'estratto conto validi.",
        'mismatch' => "Il saldo dell'estratto conto non corrisponde ancora al saldo compensato — modifica le righe compensate o il saldo inserito finché la differenza è zero.",
    ],

    'toast' => [
        'nothing_to_lock' => "Non c'è nulla da bloccare per questa data di estratto conto.",
        'complete' => 'Riconciliazione completata — :count righe bloccate.',
    ],
];
