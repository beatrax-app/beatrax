<?php

declare(strict_types=1);

return [
    'page_title' => "Anteprima dell'importazione",
    'heading' => "Anteprima dell'importazione",
    'discard' => "Scarta l'importazione",
    'confirm' => "Conferma l'importazione",
    'subtitle' => 'Rivedi le righe analizzate. Non viene salvato nulla nel tuo registro finché non confermi.',

    'expired_html' => 'Anteprima scaduta. <a href="/imports/new" class="underline">Ricarica il file</a> per riprovare.',

    'save_name' => 'Salva il nome',
    'account_name_label' => 'Nome del conto',
    'account_placeholder' => 'es. Conto di risparmio principale',
    'rename_aria' => 'Rinomina questa controparte',

    'unknown_iban_prefix' => 'Abbiamo trovato un IBAN sconosciuto:',
    'unknown_iban_suffix' => 'Dai un nome a questo conto.',

    'ics' => [
        'heading' => 'Dai un nome al tuo conto carta ICS.',
        'help' => "È la prima volta che importi dati ICS. Dai un nome a questa carta perché compaia sempre allo stesso modo in tutta l'app.",
        'placeholder' => 'es. Carta ICS',
    ],

    'paypal' => [
        'heading' => 'Dai un nome al tuo conto PayPal.',
        'help' => "È la prima volta che importi dati PayPal. Dai un nome a questo portafoglio perché compaia sempre allo stesso modo in tutta l'app.",
        'placeholder' => 'es. PayPal',
    ],

    'col_date' => 'Data',
    'col_funding_source' => 'Fonte di finanziamento',
    'col_counterparty' => 'Controparte',
    'col_amount' => 'Importo',
    'col_status' => 'Stato',

    'status' => [
        'new' => 'Nuova',
        'new_title' => 'Verrà aggiunta al tuo registro.',
        'duplicate' => 'Duplicata',
        'duplicate_title' => 'Già importata — verrà saltata.',
        'enriched' => 'Arricchita',
        'enriched_title' => 'La riga esistente verrà aggiornata con un riferimento di origine più affidabile.',
        'error' => 'Errore',
    ],

    'chain' => [
        'heading' => 'Risoluzione delle catene…',
        'pending' => 'In coda. Il risolutore delle catene partirà a breve.',
        'running' => 'Collegamento delle catene di finanziamento e scomposizione dei regolamenti di estratto conto.',
        'failed_prefix' => 'Risoluzione delle catene non riuscita:',
        'unknown_error' => 'si è verificato un errore sconosciuto',
        'open_horizon' => 'Apri Horizon',
        'failed_suffix' => 'per riprovare o ispezionare.',
    ],

    'errors' => [
        'iban_not_in_preview' => "Questo IBAN non fa parte dell'anteprima attuale.",
    ],
];
