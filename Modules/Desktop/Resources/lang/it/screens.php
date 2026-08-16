<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Benvenuto',
        'heading' => 'Benvenuto in Beatrax',
        'subtitle' => 'La tua dashboard finanziaria solo locale è pronta. Crea il tuo primo conto per iniziare.',
        'get_started' => 'Inizia',
    ],

    'setup' => [
        'page_title' => 'Configurazione…',
        'pending_heading' => 'Configurazione…',
        'pending_body' => 'Beatrax sta preparando i tuoi dati. Ci vuole solo un momento.',
        'failed_body' => 'La configurazione non è riuscita a completarsi. Riavvia Beatrax; se continua a fallire, il log riporta il motivo.',
        'ready_heading' => 'Pronto',
        'ready_body' => 'Configurazione completata. Si prosegue…',
    ],

    'staging' => [
        'page_title' => 'File ricevuto',
        'heading_prefix' => 'File ricevuto: ',
        'button_label' => "Avvia l'importazione",
        'csv_subtitle' => "Un export bancario o PayPal — avvia l'importazione per vedere l'anteprima e confermare.",
        'eml_subtitle' => "Una ricevuta email — avvia l'importazione per allegarla alla sua transazione.",
        'empty_heading' => 'Non siamo riusciti ad aprire quel file',
        'empty_body' => 'Beatrax non è riuscito a leggere il file che hai aperto. Prova invece a importarlo dalla pagina Importazioni.',
        'open_imports' => 'Apri Importazioni',
    ],

    'close' => [
        'title' => 'Tenere Beatrax in esecuzione?',
        'body' => 'Chiudendo la finestra puoi uscire del tutto da Beatrax oppure lasciarlo attivo in silenzio nella barra dei menu, così le scansioni email pianificate continuano.',
        'button_quit' => 'Esci da Beatrax',
        'button_keep_in_tray' => 'Lascia attivo nella barra dei menu',
        'checkbox_remember' => 'Ricorda la mia scelta',
    ],
];
