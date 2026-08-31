<?php

declare(strict_types=1);

return [
    'heading' => 'Dnevniki',
    'subtitle' => 'Sprotno sledenje današnji datoteki dnevnika Laravel z dvojnim prekrivanjem ob zapisu in ob prenosu.',
    'truncate' => 'Izprazni',
    'truncate_confirm' => 'Izprazniti današnjo datoteko dnevnika? Tega ni mogoče razveljaviti.',
    'truncate_title' => 'Izprazni današnjo datoteko dnevnika (ohrani inode, da se sledenje čisto nadaljuje)',
    'filters_aria' => 'Filtri dnevnika',
    'severity_aria' => 'Filter resnosti',
    'channel_placeholder' => 'Filter kanala…',
    'channel_aria' => 'Filter kanala',
    'contains_placeholder' => 'Išči po prikazanem…',
    'contains_aria' => 'Filter vsebine',
    'pause' => 'Začasno ustavi',
    'resume' => 'Nadaljuj',
    'waiting' => 'Čakanje na vrstice dnevnika…',
    'copy' => 'Kopiraj',
    'copy_title' => 'Kopiraj celoten vnos',
    'copy_title_copied' => 'Kopirano',
    'copy_aria' => 'Kopiraj vnos dnevnika',
    'copy_aria_copied' => 'Kopirano v odložišče',
    'dismiss' => 'Opusti',
    'dismiss_title' => 'Odstrani iz prikaza (datoteke dnevnika ne spremeni)',
    'dismiss_aria' => 'Odstrani vnos dnevnika iz prikaza',
    'totals' => [
        'showing' => 'Prikazano :shown od :count prejete vrstice (medpomnilnik do :cap)|Prikazano :shown od :count prejetih vrstic (medpomnilnik do :cap)|Prikazano :shown od :count prejetih vrstic (medpomnilnik do :cap)|Prikazano :shown od :count prejetih vrstic (medpomnilnik do :cap)',
        'lines_today' => ':count vrstica danes|:count vrstici danes|:count vrstice danes|:count vrstic danes',
        'lines_today_capped' => 'več kot :count vrstica danes|več kot :count vrstici danes|več kot :count vrstice danes|več kot :count vrstic danes',
        'today' => 'danes',
        'all_files' => ':size v :count dnevni datoteki|:size v :count dnevnih datotekah|:size v :count dnevnih datotekah|:size v :count dnevnih datotekah',
    ],

    'status' => [
        'poll_interrupted' => 'Poizvedba dnevnika prekinjena. Poskušam znova…',
        'paused' => 'Začasno ustavljeno.',
        'copy_failed_prefix' => 'Kopiranje ni uspelo: ',
        'clipboard_unavailable' => 'odložišče ni na voljo',
    ],

    'toast' => [
        'truncated' => 'Dnevnik izpraznjen — sproščeno :size.',
        'nothing' => 'Ni česa izprazniti.',
    ],
];
