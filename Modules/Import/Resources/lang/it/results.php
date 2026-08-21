<?php

declare(strict_types=1);

return [
    'page_title' => 'Importazione completata',
    'heading' => 'Importazione completata',

    'summary' => 'Importata :count transazione|Importate :count transazioni',
    'summary_duplicates' => ' · saltato :count duplicato| · saltati :count duplicati',
    'summary_enriched' => ' · :count arricchite',
    'summary_errors' => ' · :count errore| · :count errori',

    'show_duplicates' => 'Mostra i duplicati saltati (:count)',
    'duplicates_help' => 'I duplicati sono righe già presenti nel tuo registro — vengono saltate senza avviso quando reimporti.',
    'show_errors' => 'Mostra gli errori (:count)',
    'errors_help' => 'Gli errori sono righe che non è stato possibile analizzare; non sono state aggiunte al tuo registro.',

    'upload_another' => 'Carica un altro estratto conto',

    'issues' => [
        'row' => 'Riga :row: :reason',
        'file_stopped' => 'Non è stato possibile leggere il file oltre la riga :row. Nulla dopo quella riga è stato importato.',
        'file_none' => 'Non è stato possibile leggere il file per niente.',
        'detail' => 'Il lettore ha segnalato: :reason',
        'duplicate' => 'La riga :row era già nel tuo registro.',
        'more' => '+ :count non elencate',
        'unknown_reason' => 'Non è stato registrato alcun motivo.',
    ],
];
