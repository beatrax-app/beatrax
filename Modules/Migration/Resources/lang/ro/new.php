<?php

declare(strict_types=1);

return [
    'page_title' => 'Importă din YNAB / Actual',

    'eyebrow' => 'Migrări',
    'heading' => 'Importă din YNAB / Actual',
    'intro' => 'Adu-ți arborele de categorii, istoricul bugetelor și tranzacțiile din YNAB4, noul YNAB sau Actual Budget. Nimic nu se scrie în registrul tău până nu verifici și confirmi.',
    'reconcile_context' => 'Se caută actualizări față de ultimul tău import :product.',

    'source_label' => 'Sursă',
    'file_label' => 'Fișier',
    'parse_button' => 'Analizează exportul',

    'hints' => [
        'ynab4' => 'Exportă bugetul complet ca fișier ZIP din meniul File → Export al YNAB4.',
        'nynab' => 'Exportă bugetul din nYNAB prin File → Export Budget, apoi arhivează în ZIP fișierele CSV exportate.',
        'actual' => 'Exportă bugetul ca fișier ZIP din Settings → Export data al Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'Acesta nu pare un export YNAB4, nYNAB sau Actual pe care îl putem citi. Verifică fișierul și încearcă din nou.',
        'file_too_large' => 'Fișierul este prea mare pentru un export de migrare.',
        'archive_reader_unavailable' => 'Această versiune a aplicației nu are niciun cititor ZIP care să deschidă acest export, așa că nu poate fi citit aici. Importă-l în aplicația de birou sau rearhivează exportul cu o compresie obișnuită.',
        'internal_detail' => 'Aplicația nu a putut citi acest export (:code). Detaliile complete se află în jurnalul aplicației; menționează acest cod dacă raportezi o problemă.',
    ],
];
