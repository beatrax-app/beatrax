<?php

declare(strict_types=1);

return [
    'page_title' => 'Uvoz iz YNAB / Actual',

    'eyebrow' => 'Migracije',
    'heading' => 'Uvoz iz YNAB / Actual',
    'intro' => 'Prenesi svoje drevo kategorij, zgodovino proračuna in transakcije iz YNAB4, novega YNAB ali Actual Budget. V glavno knjigo se ne zapiše nič, dokler ne pregledaš in potrdiš.',
    'reconcile_context' => 'Preverjanje novosti glede na tvoj zadnji uvoz iz :product.',

    'source_label' => 'Vir',
    'file_label' => 'Datoteka',
    'parse_button' => 'Razčleni izvoz',

    'hints' => [
        'ynab4' => 'Izvozi celoten proračun kot datoteko ZIP iz menija File → Export v YNAB4.',
        'nynab' => 'Izvozi proračun iz nYNAB prek File → Export Budget, nato izvožene datoteke CSV stisni v ZIP.',
        'actual' => 'Izvozi proračun kot datoteko ZIP iz Settings → Export data v Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'To ni videti kot izvoz iz YNAB4, nYNAB ali Actual, ki bi ga znali prebrati. Preveri datoteko in poskusi znova.',
        'file_too_large' => 'Ta datoteka je prevelika za izvoz za migracijo.',
        'archive_reader_unavailable' => 'Ta različica aplikacije nima bralnika ZIP, ki bi odprl ta izvoz, zato ga tu ni mogoče prebrati. Uvozi ga v namizni aplikaciji ali izvoz znova zapakiraj z običajnim stiskanjem.',
        'internal_detail' => 'Aplikacija tega izvoza ni mogla prebrati (:code). Vse podrobnosti so v dnevniku aplikacije; pri prijavi težave navedi to kodo.',
    ],
];
