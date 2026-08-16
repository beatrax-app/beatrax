<?php

declare(strict_types=1);

return [
    'about_heading' => 'O zajedničkoj listi',
    'about_body' => 'Priložena YAML datoteka koja preslikava nejasne šifre sa bankovnih izvoda u razumljive nazive trgovaca. Kad je uključena, Beatrax čita listu pri uvozu; slanje predloga otvara GitHub u tvom pregledaču.',

    'mappings' => 'Preslikavanja',
    'contributors' => 'Doprinosioci',

    'use_shared_list' => [
        'title' => 'Koristi zajedničku listu trgovaca',
        'help' => 'Dozvoli Beatraxu da iz priložene liste popuni razumljive nazive trgovaca koje sam nisi preimenovao.',
    ],

    'offer_to_contribute' => [
        'title' => 'Ponudi doprinos',
        'help' => 'Prikaži poziv „Pomozi drugima da ovo prepoznaju” u redu trijaže da jednim klikom pošalješ predlog za zajedničku listu.',
    ],

    'update_on_updates' => [
        'title' => 'Ažuriraj zajedničku listu pri ažuriranjima aplikacije',
        'help' => 'Osveži priloženu listu svaki put kad se Beatrax ažurira.',
        'note' => 'Aktivira se sa budućim ažuriranjem aplikacije — trenutnu verziju vidi u Podešavanja → O aplikaciji.',
    ],
];
