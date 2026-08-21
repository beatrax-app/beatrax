<?php

declare(strict_types=1);

return [
    'page_title' => 'Import finalizat',
    'heading' => 'Import finalizat',

    'summary' => ':count tranzacție importată|:count tranzacții importate|:count de tranzacții importate',
    'summary_duplicates' => ' · :count duplicat omis| · :count duplicate omise| · :count de duplicate omise',
    'summary_enriched' => ' · :count îmbogățite',
    'summary_errors' => ' · :count eroare| · :count erori| · :count de erori',

    'show_duplicates' => 'Arată duplicatele omise (:count)',
    'duplicates_help' => 'Duplicatele sunt rânduri deja prezente în registrul tău — la reimportare sunt omise fără avertisment.',
    'show_errors' => 'Arată erorile (:count)',
    'errors_help' => 'Erorile sunt rânduri care nu au putut fi analizate; nu au fost adăugate în registrul tău.',

    'upload_another' => 'Încarcă alt extras de cont',

    'issues' => [
        'row' => 'Rândul :row: :reason',
        'file_stopped' => 'Fișierul nu a putut fi citit dincolo de rândul :row. Nimic după acel rând nu a fost importat.',
        'file_none' => 'Fișierul nu a putut fi citit deloc.',
        'detail' => 'Cititorul a raportat: :reason',
        'duplicate' => 'Rândul :row era deja în registrul tău.',
        'more' => '+ :count nelistate',
        'unknown_reason' => 'Nu a fost înregistrat niciun motiv.',
    ],
];
