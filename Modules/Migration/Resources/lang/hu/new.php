<?php

declare(strict_types=1);

return [
    'page_title' => 'Import YNAB-ból / Actualból',

    'eyebrow' => 'Migrálások',
    'heading' => 'Import YNAB-ból / Actualból',
    'intro' => 'Hozd át a kategóriafádat, a költségvetési előzményeidet és a tranzakcióidat a YNAB4-ből, az új YNAB-ból vagy az Actual Budgetből. Semmi nem kerül a főkönyvedbe, amíg át nem nézed és meg nem erősíted.',
    'reconcile_context' => 'Frissítések keresése a legutóbbi :product importodhoz képest.',

    'source_label' => 'Forrás',
    'file_label' => 'Fájl',
    'parse_button' => 'Export beolvasása',

    'hints' => [
        'ynab4' => 'Exportáld a teljes költségvetésedet ZIP-fájlként a YNAB4 File → Export menüjéből.',
        'nynab' => 'Exportáld a költségvetésedet az nYNAB-ból a File → Export Budget menüponttal, majd csomagold ZIP-be az exportált CSV-fájlokat.',
        'actual' => 'Exportáld a költségvetésedet ZIP-fájlként az Actual Budget Settings → Export data pontjából.',
    ],

    'errors' => [
        'unrecognised' => 'Ez nem tűnik olyan YNAB4-, nYNAB- vagy Actual-exportnak, amelyet be tudunk olvasni. Ellenőrizd a fájlt, és próbáld újra.',
        'file_too_large' => 'Ez a fájl túl nagy egy migrálási exporthoz.',
        'archive_reader_unavailable' => 'Az alkalmazás ezen változatában nincs olyan ZIP-olvasó, amely ezt az exportot meg tudná nyitni, így itt nem olvasható. Importáld az asztali alkalmazásban, vagy csomagold újra az exportot szokásos tömörítéssel.',
        'internal_detail' => 'Az alkalmazás nem tudta beolvasni ezt az exportot (:code). A teljes részletek az alkalmazásnaplóban vannak; hibabejelentéskor add meg ezt a kódot.',
    ],
];
