<?php

declare(strict_types=1);

return [
    'heading' => 'Logovi',
    'subtitle' => 'Praćenje uživo današnje Laravel log datoteke, sa dvostrukim redigovanjem pri upisu i pri prenosu.',
    'truncate' => 'Isprazni',
    'truncate_confirm' => 'Isprazniti današnju log datoteku? Ovo ne može da se poništi.',
    'truncate_title' => 'Isprazni današnju log datoteku (zadržava inode pa se praćenje uredno nastavlja)',
    'filters_aria' => 'Filteri logova',
    'severity_aria' => 'Filter ozbiljnosti',
    'channel_placeholder' => 'Filter kanala…',
    'channel_aria' => 'Filter kanala',
    'contains_placeholder' => 'Pretraži prikazano…',
    'contains_aria' => 'Filter sadržaja',
    'pause' => 'Pauziraj',
    'resume' => 'Nastavi',
    'waiting' => 'Čekanje redova loga…',
    'copy' => 'Kopiraj',
    'copy_title' => 'Kopiraj ceo unos',
    'copy_title_copied' => 'Kopirano',
    'copy_aria' => 'Kopiraj unos loga',
    'copy_aria_copied' => 'Kopirano u ostavu',
    'dismiss' => 'Odbaci',
    'dismiss_title' => 'Ukloni iz prikaza (ne menja log datoteku)',
    'dismiss_aria' => 'Ukloni unos loga iz prikaza',
    'totals' => [
        'showing' => 'Prikazano',
        'of' => 'od',
        'received' => 'primljeno (bafer do 10 hilj.)',
        'lines_today' => 'redova danas',
        'today' => 'danas',
        'across' => 'u',
        'daily_files' => 'dnevnih datoteka',
    ],

    'status' => [
        'poll_interrupted' => 'Preuzimanje loga prekinuto. Ponovni pokušaj…',
        'paused' => 'Pauzirano.',
        'copy_failed_prefix' => 'Kopiranje nije uspelo: ',
        'clipboard_unavailable' => 'ostava nije dostupna',
    ],

    'toast' => [
        'truncated' => 'Log ispražnjen — oslobođeno :size.',
        'nothing' => 'Nema šta da se isprazni.',
    ],
];
