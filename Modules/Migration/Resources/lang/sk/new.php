<?php

declare(strict_types=1);

return [
    'page_title' => 'Import z YNAB / Actual',

    'eyebrow' => 'Migrácie',
    'heading' => 'Import z YNAB / Actual',
    'intro' => 'Prenes si strom kategórií, históriu rozpočtov a transakcie z YNAB4, nového YNAB alebo Actual Budget. Do knihy sa nič nezapíše, kým to neskontroluješ a nepotvrdíš.',
    'reconcile_context' => 'Kontroluje sa, čo pribudlo od tvojho posledného importu — :product.',

    'source_label' => 'Zdroj',
    'file_label' => 'Súbor',
    'parse_button' => 'Načítať export',

    'hints' => [
        'ynab4' => 'V YNAB4 exportuj celý rozpočet ako súbor ZIP cez menu File → Export.',
        'nynab' => 'V nYNAB exportuj rozpočet cez File → Export Budget a exportované súbory CSV zabaľ do ZIP.',
        'actual' => 'V Actual Budget exportuj rozpočet ako súbor ZIP cez Settings → Export data.',
    ],

    'errors' => [
        'unrecognised' => 'Toto nevyzerá ako export z YNAB4, nYNAB ani Actual, ktorý vieme prečítať. Skontroluj súbor a skús to znova.',
        'file_too_large' => 'Tento súbor je na migračný export príliš veľký.',
        'archive_reader_unavailable' => 'Táto verzia aplikácie nemá žiadnu čítačku ZIP, ktorá by tento export otvorila, takže sa tu nedá prečítať. Naimportuj ho v aplikácii pre počítač alebo export znova zabaľ bežnou kompresiou.',
        'internal_detail' => 'Aplikácia nedokázala načítať tento export (:code). Úplné podrobnosti sú v protokole aplikácie; pri hlásení problému uveď tento kód.',
    ],
];
