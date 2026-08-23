<?php

declare(strict_types=1);

return [
    'page_title' => "Anteprima dell'importazione",
    'heading' => "Anteprima dell'importazione",
    'discard' => "Scarta l'importazione",
    'confirm' => "Conferma l'importazione",
    'subtitle' => 'Rivedi le righe analizzate. Non viene salvato nulla nel tuo registro finché non confermi.',

    'already_imported' => 'Questo file è già stato importato.',

    'already_imported_link' => 'Vedi il risultato dell\'importazione',

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
        'failed_detail' => 'i dettagli sono nel log dei job',
        'open_horizon' => 'Apri Horizon',
        'failed_suffix' => 'per riprovare o ispezionare.',
    ],

    'errors' => [
        'app_locked' => 'Sblocca l\'app per importare: le chiavi di crittografia non possono essere usate mentre è bloccata.',
        'file_unreadable' => 'Non è stato possibile leggere questo file.',
        'iban_not_in_preview' => "Questo IBAN non fa parte dell'anteprima attuale.",
        'row_unreadable' => 'Non è stato possibile leggere questa riga.',
        'unknown_account' => 'Questa riga appartiene a un conto a cui non hai ancora dato un nome.',
    ],

    'failed' => [
        'heading' => 'Non è stato possibile leggere questo file',
        'no_rows' => 'In questo file non sono state trovate transazioni, quindi non c\'è nulla da importare.',
        'nothing_read' => 'Nulla in questo file è stato leggibile come transazione, quindi non c\'è nulla da importare.',
        'every_row' => 'Nessuna riga di questo file è stata leggibile, quindi non c\'è nulla da importare. Ognuna è elencata sotto con il motivo.',
        'likely_cause' => 'Di solito la riga di intestazione non corrisponde all\'origine che hai scelto. Controlla la banca e il formato nella schermata di caricamento, oppure scarica di nuovo l\'estratto conto dalla tua banca.',
        'truncated_heading' => 'È stato possibile leggere solo una parte di questo file',
        'truncated' => 'La lettura si è fermata a metà file. Tutto ciò che segue non è stato letto e non verrà importato.',
        'some_rows' => 'Alcune righe non sono state leggibili. Sono segnalate sotto e verranno saltate; confermando si importano le altre.',
        'detail_label' => 'Cosa ha segnalato il parser:',
        'rows_read_label' => 'Righe lette',
        'rows_skipped_label' => 'Righe saltate',
    ],
];
