<?php

declare(strict_types=1);

return [
    'about_body' => 'Priložena YAML datoteka koja preslikava zagonetne šifre s bankovnih izvoda u razumljive nazive trgovaca. Kad je uključena, Beatrax čita popis pri uvozu; slanje prijedloga otvara GitHub u tvojem pregledniku.',

    'mappings' => ':count preslikavanje|:count preslikavanja|:count preslikavanja',
    'contributors' => ':count doprinositelj|:count doprinositelja|:count doprinositelja',

    'use_shared_list' => [
        'title' => 'Koristi zajednički popis trgovaca',
        'help' => 'Dopusti Beatraxu da iz priloženog popisa popuni razumljive nazive trgovaca koje sam nisi preimenovao.',
    ],

    'offer_to_contribute' => [
        'title' => 'Ponudi doprinos',
        'help' => 'Prikaži poziv „Pomozi drugima da ovo prepoznaju” u retku trijaže da jednim klikom pošalješ prijedlog za zajednički popis.',
        // i18n-review: hr · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Prikaži poziv „Pomozi drugima da ovo prepoznaju” u retku trijaže da jednim dodirom pošalješ prijedlog za zajednički popis.',
    ],

    'update_on_updates' => [
        'title' => 'Ažuriraj zajednički popis pri ažuriranjima aplikacije',
        'help' => 'Osvježi priloženi popis svaki put kad se Beatrax ažurira.',
        'help_phone' => 'Osvježi priloženi popis svaki put kad se s App Storea ili Google Playa instalira nova verzija Beatraxa.',
        'note' => 'Aktivira se s budućim ažuriranjem aplikacije — trenutačnu verziju vidi u Postavke → O aplikaciji.',
    ],
];
