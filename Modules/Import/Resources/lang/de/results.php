<?php

declare(strict_types=1);

return [
    'page_title' => 'Import abgeschlossen',
    'heading' => 'Import abgeschlossen',

    'summary' => ':count Transaktion importiert|:count Transaktionen importiert',
    'summary_duplicates' => ' · :count Duplikat übersprungen| · :count Duplikate übersprungen',
    'summary_enriched' => ' · :count angereichert',
    'summary_errors' => ' · :count Fehler| · :count Fehler',

    'show_duplicates' => 'Übersprungene Duplikate anzeigen (:count)',
    'duplicates_help' => 'Duplikate sind Zeilen, die bereits in deinem Hauptbuch stehen — beim erneuten Import werden sie stillschweigend übersprungen.',
    'show_errors' => 'Fehler anzeigen (:count)',
    'errors_help' => 'Fehler sind Zeilen, die nicht eingelesen werden konnten; sie wurden deinem Hauptbuch nicht hinzugefügt.',

    'upload_another' => 'Weiteren Kontoauszug hochladen',

    'issues' => [
        'row' => 'Zeile :row: :reason',
        'file_stopped' => 'Die Datei konnte nicht über Zeile :row hinaus gelesen werden. Alles danach wurde nicht importiert.',
        'file_none' => 'Die Datei konnte überhaupt nicht gelesen werden.',
        'detail' => 'Der Leser meldete: :reason',
        'duplicate' => 'Zeile :row war bereits in deinem Hauptbuch.',
        'more' => '+ :count nicht aufgeführt',
        'unknown_reason' => 'Es wurde kein Grund erfasst.',
    ],
];
