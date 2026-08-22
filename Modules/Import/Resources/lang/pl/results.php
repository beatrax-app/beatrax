<?php

declare(strict_types=1);

return [
    'page_title' => 'Import zakończony',
    'heading' => 'Import zakończony',

    'summary' => 'Zaimportowano :count transakcję|Zaimportowano :count transakcje|Zaimportowano :count transakcji',
    'summary_duplicates' => ' · pominięto :count duplikat| · pominięto :count duplikaty| · pominięto :count duplikatów',
    'summary_enriched' => ' · wzbogacone: :count',
    'summary_errors' => ' · :count błąd| · :count błędy| · :count błędów',

    'show_duplicates' => 'Pokaż pominięte duplikaty (:count)',
    'duplicates_help' => 'Duplikaty to wiersze już obecne w Twojej księdze — przy ponownym imporcie są po cichu pomijane.',
    'show_errors' => 'Pokaż błędy (:count)',
    'errors_help' => 'Błędy to wiersze, których nie udało się przetworzyć; nie zostały dodane do Twojej księgi.',

    'upload_another' => 'Wgraj kolejny wyciąg',

    'issues' => [
        'row' => 'Wiersz :row: :reason',
        'file_stopped' => 'Pliku nie udało się odczytać dalej niż do wiersza :row. Nic po tym wierszu nie zostało zaimportowane.',
        'file_none' => 'Pliku nie udało się odczytać w ogóle.',
        'detail' => 'Czytnik zgłosił: :reason',
        'duplicate' => 'Wiersz :row był już w Twojej księdze.',
        'more' => '+ :count niewymienionych',
    ],
];
