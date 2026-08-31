<?php

declare(strict_types=1);

return [
    'page_title' => 'Riconcilia',
    'heading' => 'Riconcilia',
    'intro' => "Confronta il saldo dell'estratto conto di un conto con le tue transazioni compensate. Quando coincidono, completa la riconciliazione per bloccare quelle righe.",

    'account' => 'Conto',
    'choose_account' => 'Scegli un conto…',
    'statement_date' => 'Data estratto conto',
    'statement_balance' => 'Saldo estratto conto (:symbol)',
    'balance_help' => 'Precompilato dal tuo ultimo estratto conto importato quando disponibile — negativo per il denaro dovuto, comunque modificabile.',

    'cleared_balance' => 'Saldo compensato',
    'statement_target' => 'Obiettivo estratto conto',
    'difference' => 'Differenza',

    'pill' => [
        'choose_account' => 'scegli un conto',
        'choose_date' => 'scegli la data dell’estratto conto',
        'enter_balance' => 'inserisci un saldo estratto conto',
        'matched' => 'coincide — :amount',
        'discrepancy' => 'discrepanza — :amount',
        'reconciled_through' => 'riconciliato fino al :date',
    ],

    'mismatch_html' => 'Il saldo del tuo estratto conto non corrisponde ancora al saldo compensato. Cambia lo stato delle righe compensate nella <a href=":url" class="underline">lista delle transazioni</a> oppure modifica il saldo inserito finché la differenza raggiunge zero — questo flusso non crea mai una scrittura di pareggio.',
    'unreachable_no_baseline_html' => 'Nessuna combinazione di righe può portare questa differenza a zero. Questo conto non ha un saldo iniziale registrato, quindi il suo saldo è misurato da zero. Importa l\'estratto conto con cui il conto si apre, oppure imposta il saldo iniziale in <a href=":url" class="underline">Impostazioni</a>.',
    'unreachable' => 'Nessuna combinazione di righe può portare questa differenza a zero: è fuori dall\'intervallo di tutte le righe di questo conto fino alla data indicata. Controlla la data dell\'estratto conto e il saldo inserito.',

    'check' => 'Verifica',
    'complete' => 'Completa la riconciliazione',
    'complete_unavailable' => 'Fino a questa data non c’è più nulla da bloccare — segna altre righe come compensate o scegli una data di estratto conto successiva.',

    'errors' => [
        'choose_account' => 'Scegli prima un conto.',
        'invalid_balance_date' => "Inserisci un saldo e una data dell'estratto conto validi.",
        'mismatch' => "Il saldo dell'estratto conto non corrisponde ancora al saldo compensato — modifica le righe compensate o il saldo inserito finché la differenza è zero.",
    ],

    'toast' => [
        'nothing_to_lock' => "Non c'è nulla da bloccare per questa data di estratto conto.",
        'complete' => 'Riconciliazione completata — :count riga bloccata.|Riconciliazione completata — :count righe bloccate.',
    ],
];
