<?php

declare(strict_types=1);

return [
    'about_heading' => 'O zajedničkom popisu',
    'about_body' => 'Priložena YAML datoteka koja preslikava zagonetne šifre s bankovnih izvoda u razumljive nazive trgovaca. Kad je uključena, Beatrax čita popis pri uvozu; slanje prijedloga otvara GitHub u tvojem pregledniku.',

    'mappings' => 'Preslikavanja',
    'contributors' => 'Doprinositelji',

    'use_shared_list' => [
        'title' => 'Koristi zajednički popis trgovaca',
        'help' => 'Dopusti Beatraxu da iz priloženog popisa popuni razumljive nazive trgovaca koje sam nisi preimenovao.',
    ],

    'offer_to_contribute' => [
        'title' => 'Ponudi doprinos',
        'help' => 'Prikaži poziv „Pomozi drugima da ovo prepoznaju” u retku trijaže da jednim klikom pošalješ prijedlog za zajednički popis.',
    ],

    'update_on_updates' => [
        'title' => 'Ažuriraj zajednički popis pri ažuriranjima aplikacije',
        'help' => 'Osvježi priloženi popis svaki put kad se Beatrax ažurira.',
        'note' => 'Aktivira se s budućim ažuriranjem aplikacije — trenutačnu verziju vidi u Postavke → O aplikaciji.',
    ],
];
