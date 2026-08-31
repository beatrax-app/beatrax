<?php

declare(strict_types=1);

return [
    'page_title' => 'Importēt no YNAB / Actual',

    'eyebrow' => 'Datu pārneses',
    'heading' => 'Importēt no YNAB / Actual',
    'intro' => 'Pārnesiet savu kategoriju koku, budžeta vēsturi un darījumus no YNAB4, jaunā YNAB vai Actual Budget. Nekas netiek ierakstīts jūsu virsgrāmatā, kamēr neesat to pārskatījuši un apstiprinājuši.',
    'reconcile_context' => 'Meklē atjauninājumus salīdzinājumā ar jūsu pēdējo :product importu.',

    'source_label' => 'Avots',
    'file_label' => 'Fails',
    'parse_button' => 'Nolasīt eksportu',

    'hints' => [
        'ynab4' => 'Eksportējiet visu savu budžetu kā ZIP failu no YNAB4 izvēlnes File → Export.',
        'nynab' => 'Eksportējiet savu budžetu no nYNAB caur File → Export Budget, pēc tam saarhivējiet eksportētos CSV failus ZIP formātā.',
        'actual' => 'Eksportējiet savu budžetu kā ZIP failu no Actual Budget sadaļas Settings → Export data.',
    ],

    'errors' => [
        'unrecognised' => 'Šis neizskatās pēc YNAB4, nYNAB vai Actual eksporta, ko varam nolasīt. Pārbaudiet failu un mēģiniet vēlreiz.',
        'file_too_large' => 'Šis fails ir pārāk liels datu pārneses eksportam.',
        'archive_reader_unavailable' => 'Šai lietotnes versijai nav ZIP lasītāja, kas varētu atvērt šo eksportu, tāpēc to šeit nevar izlasīt. Importē to datora lietotnē vai iepako eksportu no jauna ar parastu saspiešanu.',
        'internal_detail' => 'Lietotne nevarēja nolasīt šo eksportu (:code). Pilnas ziņas ir lietotnes žurnālā; ziņojot par problēmu, norādi šo kodu.',
    ],
];
