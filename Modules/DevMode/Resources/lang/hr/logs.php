<?php

declare(strict_types=1);

return [
    'heading' => 'Zapisnici',
    'subtitle' => 'Praćenje uživo današnje Laravel log datoteke, s dvostrukim redigiranjem pri zapisu i pri prijenosu.',
    'truncate' => 'Isprazni',
    'truncate_confirm' => 'Isprazniti današnju log datoteku? Ovo se ne može poništiti.',
    'truncate_title' => 'Isprazni današnju log datoteku (zadržava inode pa se praćenje uredno nastavlja)',
    'filters_aria' => 'Filtri zapisnika',
    'severity_aria' => 'Filtar ozbiljnosti',
    'channel_placeholder' => 'Filtar kanala…',
    'channel_aria' => 'Filtar kanala',
    'contains_placeholder' => 'Pretraži prikazano…',
    'contains_aria' => 'Filtar sadržaja',
    'pause' => 'Pauziraj',
    'resume' => 'Nastavi',
    'waiting' => 'Čekanje redaka zapisnika…',
    'copy' => 'Kopiraj',
    'copy_title' => 'Kopiraj cijeli unos',
    'copy_title_copied' => 'Kopirano',
    'copy_aria' => 'Kopiraj unos zapisnika',
    'copy_aria_copied' => 'Kopirano u međuspremnik',
    'dismiss' => 'Odbaci',
    'dismiss_title' => 'Ukloni iz prikaza (ne mijenja log datoteku)',
    'dismiss_aria' => 'Ukloni unos zapisnika iz prikaza',
    'totals' => [
        'showing' => 'Prikazano :shown od :count primljenog retka (međuspremnik do :cap)|Prikazano :shown od :count primljena retka (međuspremnik do :cap)|Prikazano :shown od :count primljenih redaka (međuspremnik do :cap)',
        'lines_today' => ':count redak danas|:count retka danas|:count redaka danas',
        'lines_today_capped' => 'više od :count retka danas|više od :count retka danas|više od :count redaka danas',
        'today' => 'danas',
        'all_files' => ':size u :count dnevnoj datoteci|:size u :count dnevne datoteke|:size u :count dnevnih datoteka',
    ],

    'status' => [
        'poll_interrupted' => 'Dohvat zapisnika prekinut. Ponovni pokušaj…',
        'paused' => 'Pauzirano.',
        'copy_failed_prefix' => 'Kopiranje nije uspjelo: ',
        'clipboard_unavailable' => 'međuspremnik nije dostupan',
    ],

    'toast' => [
        'truncated' => 'Zapisnik ispražnjen — oslobođeno :size.',
        'nothing' => 'Nema što isprazniti.',
    ],
];
