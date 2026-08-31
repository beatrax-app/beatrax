<?php

declare(strict_types=1);

return [
    'page_title' => 'Import z YNAB / Actual',

    'eyebrow' => 'Migrace',
    'heading' => 'Import z YNAB / Actual',
    'intro' => 'Přenes si strom kategorií, historii rozpočtů a transakce z YNAB4, nového YNAB nebo Actual Budget. Do tvé knihy se nic nezapíše, dokud to nezkontroluješ a nepotvrdíš.',
    'reconcile_context' => 'Kontrolujeme změny oproti tvému poslednímu importu (:product).',

    'source_label' => 'Zdroj',
    'file_label' => 'Soubor',
    'parse_button' => 'Načíst export',

    'hints' => [
        'ynab4' => 'Vyexportuj celý rozpočet jako ZIP z nabídky File → Export v YNAB4.',
        'nynab' => 'Vyexportuj rozpočet z nYNAB přes File → Export Budget a pak vyexportované CSV soubory zabal do ZIPu.',
        'actual' => 'Vyexportuj rozpočet jako ZIP ze Settings → Export data v Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'Tohle nevypadá jako export z YNAB4, nYNAB ani Actual, který umíme přečíst. Zkontroluj soubor a zkus to znovu.',
        'file_too_large' => 'Tento soubor je na migrační export příliš velký.',
        'archive_reader_unavailable' => 'Tato verze aplikace nemá žádnou čtečku ZIP, která by tento export otevřela, takže ho tu nelze přečíst. Naimportuj ho v aplikaci pro počítač, nebo export znovu zabal běžnou kompresí.',
        'internal_detail' => 'Aplikace nedokázala načíst tento export (:code). Úplné podrobnosti jsou v protokolu aplikace; při hlášení problému uveď tento kód.',
    ],
];
