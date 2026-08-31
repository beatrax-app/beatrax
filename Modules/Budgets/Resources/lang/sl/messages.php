<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Proračuni',
        'subtitle' => 'Razporedi vse do zadnjega — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Prejšnje obdobje',
        'next_aria' => 'Naslednje obdobje',
    ],

    'ready' => [
        'label' => 'Pripravljeno za razporeditev',
        'overassigned' => 'Razporejenega je več, kot imaš — zmanjšaj kakšno ovojnico ali počakaj na nove prihodke.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Ničesar še ni razporejenega',
        'copy_hint' => 'Kopiraj načrt prejšnjega meseca ali klikni na celico spodaj in začni razporejati.',
        'first_hint' => 'Klikni na celico spodaj in začni razporejati svoj prvi mesec.',
        'copy_button' => 'Kopiraj prejšnji mesec',
    ],

    'no_categories' => [
        'heading' => 'Še ni kategorij odhodkov',
        'body' => 'Dodaj kategorijo odhodkov in ji začni razporejati denar.',
    ],

    'table' => [
        'category' => 'Kategorija',
        'assigned' => 'Razporejeno',
        'carried_in' => 'Preneseno',
        'moved' => 'Premaknjeno',
        'spent' => 'Porabljeno',
        'available' => 'Na voljo',
        'if_overspent' => 'Če je prekoračeno',
        'notify_at' => 'Obvesti pri',
        'actions' => 'Dejanja',
    ],

    'badge' => [
        'carries_negative' => 'Prenaša minus',
        'unconverted_aria' => 'Poraba v valuti brez razpoložljivega tečaja se tu ne šteje — poglej nadzorno ploščo',
        'unconverted_title' => 'Poraba brez razpoložljivega tečaja se tu ne šteje — poglej nadzorno ploščo',
        'over_budget' => ':count čez proračun',
    ],

    'row' => [
        'assigned_aria' => 'Razporejeno za :category',
        'overspend_aria' => 'Če je :category prekoračena',
        'notify_aria' => 'Obvesti me pri odstotku porabe za :category',
        'move_money' => 'Premakni denar',
        'move' => 'Premakni',
    ],

    'overspend' => [
        'reduce' => 'Zmanjšaj znesek, pripravljen za razporeditev naslednji mesec',
        'carry' => 'Prenesi minus v tej ovojnici',
    ],

    'history' => [
        'show' => 'Prikaži zgodovino ↓',
        'hide' => 'Skrij zgodovino ↑',
        'moved_from' => 'Premaknjeno iz :category',
        'moved_to' => 'Premaknjeno v :category',
        'moved_unreadable' => 'Premaknjeno z :category z novejšo različico Beatraxa',
        'undo' => 'Razveljavi',
    ],

    'phone' => [
        'spent' => 'Porabljeno :amount',
        'carried_in' => 'Preneseno :amount',
        'moved' => 'Premaknjeno :amount',
        'available' => 'Na voljo :amount',
        'notify_at' => 'Obvesti pri',
    ],

    'modal' => [
        'move_from' => 'Premakni iz :name',
        'move_from_fallback' => 'ovojnica',
        'move_to' => 'Premakni v',
        'no_other' => 'Ni drugih ovojnic',
        'select' => 'Izberi ovojnico',
        'amount' => 'Znesek',
        'available_in' => 'Na voljo v :name: :amount',
        'note' => 'Opomba (neobvezno)',
        'note_placeholder' => 'npr. Pokrivanje prekoračitve za restavracije',
        'cancel' => 'Prekliči',
        'move_funds' => 'Premakni sredstva',
    ],

    'glance' => [
        'see_all' => 'Prikaži vse →',
    ],

    'notices' => [
        'invalid_amount' => 'Vnesi veljaven znesek.',
        'threshold_range' => 'Vnesi celo število med 1 in 200.',
        'copied_last_month' => 'Načrt prejšnjega meseca je kopiran.',
        'choose_envelope' => 'Izberi ovojnico, v katero želiš premakniti denar.',
        'amount_positive' => 'Vnesi znesek, večji od nič.',
        'move_failed' => 'Premika ni bilo mogoče dokončati — poskusi znova.',
        'money_moved' => 'Denar je premaknjen.',
        'move_undone' => 'Premik je razveljavljen.',
    ],

    'errors' => [
        'assigned_negative' => 'Razporejeni znesek ne more biti negativen.',
        'invalid_overspend_mode' => 'Neveljaven način obravnave prekoračitve.',
        'threshold_range' => 'Prag obvestila mora biti med 1 in 200.',
        'same_envelope' => 'Izvorna in ciljna ovojnica morata biti različni.',
        'non_positive_amount' => 'Neveljaven ali nepozitiven znesek.',
        'category_not_found' => 'Kategorija ni najdena ali uporabnik nima dostopa do nje.',
    ],
];
