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
    'unreadable_html' => 'Non è possibile leggere l\'anteprima. <a href="/imports/new" class="underline">Ricarica il file</a> per riprovare.',

    'save_name' => 'Salva il nome',
    'account_name_label' => 'Nome del conto',
    'account_placeholder' => 'es. Conto di risparmio principale',
    'rename_aria' => 'Rinomina questa controparte',

    'unknown_iban_prefix' => 'Abbiamo trovato un IBAN sconosciuto:',

    'unknown_account_prefix' => 'Abbiamo trovato un conto sconosciuto:',
    'unknown_iban_suffix' => 'Dai un nome a questo conto.',

    'ics' => [
        'name' => 'Carta ICS',
        'heading' => 'Dai un nome al tuo conto carta ICS.',
        'help' => "È la prima volta che importi dati ICS. Dai un nome a questa carta perché compaia sempre allo stesso modo in tutta l'app.",
        'placeholder' => 'es. Carta ICS',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Dai un nome al tuo conto PayPal.',
        'help' => "È la prima volta che importi dati PayPal. Dai un nome a questo portafoglio perché compaia sempre allo stesso modo in tutta l'app.",
        'placeholder' => 'es. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Dai un nome al tuo conto Google Play.',
        'help' => "È la prima volta che importi una ricevuta Google Play. Dai un nome a questo conto perché compaia sempre allo stesso modo in tutta l'app.",
        'placeholder' => 'es. Google Play',
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

    'rows_shown' => 'Righe mostrate: :shown su :total',

    'show_more' => 'Mostra altre righe',

    'errors' => [
        'app_locked' => 'Sblocca l\'app per importare: le chiavi di crittografia non possono essere usate mentre è bloccata.',
        'archive_holds_one_message' => 'Questo file è un singolo messaggio email, non un archivio di casella, quindi letto come archivio non contiene nulla. Caricalo di nuovo con il formato Messaggio email.',
        'email_file_is_an_archive' => 'Questo file è un archivio di casella: contiene più di un messaggio, e letto come singolo messaggio ne prenderebbe solo il primo. Caricalo di nuovo con il formato Archivio di casella.',
        'file_stopped_short' => 'La riga di intestazione corrispondeva, quindi il formato è giusto. La lettura si è fermata prima della fine del file. Basta una riga illeggibile, oppure un file troppo grande per questo dispositivo. Prova un periodo più breve.',
        'file_unreadable' => 'Non è stato possibile leggere questo file.',
        'file_unreadable_detail' => 'L\'app non è riuscita a leggere questo file (:code). I dettagli completi sono nel registro dell\'app; cita questo codice se segnali un problema.',
        'iban_not_in_preview' => "Questo IBAN non fa parte dell'anteprima attuale.",
        'not_an_email_file' => 'Questo file non è né un messaggio email né un archivio di casella, quindi non c\'è nulla da leggere come ricevuta. Scegli il tipo di importazione e il formato che corrispondono al tuo file.',
        'pdf_has_no_text_layer' => 'Questo PDF non contiene testo: è la scansione o la foto di un estratto conto, quindi non c\'è nulla da leggere. Scarica l\'estratto conto vero e proprio dalla tua banca, oppure usa un export CSV.',
        'pdf_password_protected' => 'Questo PDF è protetto da password, quindi nessun lettore riesce ad aprirlo. Salva una copia senza protezione dal tuo visualizzatore PDF e importa quella.',
        'pdf_reader_unavailable' => 'Questa versione dell\'app non ha alcun lettore PDF, quindi un estratto conto in PDF non si può aprire qui. Importa questo file su un altro dispositivo, oppure usa un export CSV della tua banca.',
        'row_belongs_to_another_statement' => 'Questa riga appartiene a una transazione in un altro file di estratto conto. Importa anche quell\'estratto conto: i due vengono letti insieme.',
        'row_unreadable' => 'Non è stato possibile leggere questa riga.',
        'row_unreadable_detail' => 'L\'app non è riuscita a leggere questa riga (:code). I dettagli completi sono nel registro dell\'app; cita questo codice se segnali un problema.',
        'unknown_account' => 'Questa riga appartiene a un conto a cui non hai ancora dato un nome.',
    ],

    'receipts' => [
        'heading' => 'Questo file è stato letto come e-mail',
        'saved' => 'Quello che conteneva è elencato qui sotto, e ogni messaggio è stato salvato.',
        'none_imported' => 'Nulla di tutto ciò è diventato una transazione, quindi nel tuo registro non è stato aggiunto niente.',
        'shown' => 'Messaggi mostrati: :shown su :total',
        'no_subject' => 'Senza oggetto',

        'state' => [
            'read' => 'Letto come pagamento — conferma questa importazione per aggiungerlo al tuo registro.',
            'not_a_payment' => 'Non è un pagamento. Questo messaggio annuncia qualcosa invece di confermare un pagamento.',
            'unreadable' => 'Salvato. L\'app legge le ricevute di questo mittente, ma in questo messaggio non ha trovato importo, esercente e riferimento.',
            'unknown_sender' => 'Salvato. L\'app non legge le ricevute di questo mittente, quindi dal messaggio non ha preso nulla.',
        ],
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
