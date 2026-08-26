<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Rozpočty',
        'subtitle' => 'Prideľ všetko do posledného — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Predchádzajúce obdobie',
        'next_aria' => 'Ďalšie obdobie',
    ],

    'ready' => [
        'label' => 'Na pridelenie',
        'overassigned' => 'Pridelené je viac, než máš k dispozícii — zníž niektorú obálku alebo počkaj na ďalší príjem.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Zatiaľ nič pridelené',
        'copy_hint' => 'Skopíruj plán z minulého mesiaca alebo klikni do bunky nižšie a začni prideľovať.',
        'first_hint' => 'Klikni do bunky nižšie a začni prideľovať svoj prvý mesiac.',
        'copy_button' => 'Kopírovať minulý mesiac',
    ],

    'no_categories' => [
        'heading' => 'Zatiaľ žiadne výdavkové kategórie',
        'body' => 'Pridaj výdavkovú kategóriu a začni jej prideľovať peniaze.',
    ],

    'table' => [
        'category' => 'Kategória',
        'assigned' => 'Pridelené',
        'spent' => 'Minuté',
        'available' => 'Dostupné',
        'if_overspent' => 'Pri prečerpaní',
        'notify_at' => 'Upozorniť pri',
        'actions' => 'Akcie',
    ],

    'badge' => [
        'carries_negative' => 'Prenáša mínus',
        'unconverted_aria' => 'Výdavky v mene bez dostupného kurzu sa sem nezapočítavajú — pozri prehľad',
        'unconverted_title' => 'Výdavky bez dostupného kurzu sa sem nezapočítavajú — pozri prehľad',
        'over_budget' => 'Nad rozpočet: :count',
    ],

    'row' => [
        'assigned_aria' => 'Pridelené — :category',
        'overspend_aria' => 'Pri prečerpaní — :category',
        'notify_aria' => 'Upozorniť pri percente využitia — :category',
        'move_money' => 'Presunúť peniaze',
        'move' => 'Presunúť',
    ],

    'overspend' => [
        'reduce' => 'Znížiť sumu na pridelenie budúci mesiac',
        'carry' => 'Preniesť mínus v tejto obálke',
    ],

    'history' => [
        'show' => 'Zobraziť históriu ↓',
        'hide' => 'Skryť históriu ↑',
        'moved_from' => 'Presunuté z kategórie: :category',
        'moved_to' => 'Presunuté do kategórie: :category',
        'undo' => 'Späť',
    ],

    'phone' => [
        'spent' => 'Minuté :amount',
        'available' => 'Dostupné :amount',
        'notify_at' => 'Upozorniť pri',
    ],

    'modal' => [
        'move_from' => 'Presun z obálky: :name',
        'move_from_fallback' => 'obálka',
        'move_to' => 'Presunúť do',
        'no_other' => 'Žiadne iné obálky',
        'select' => 'Vyber obálku',
        'amount' => 'Suma',
        'available_in' => 'Dostupné (:name): :amount',
        'note' => 'Poznámka (nepovinné)',
        'note_placeholder' => 'napr. Krytie prečerpania na stravovanie',
        'cancel' => 'Zrušiť',
        'move_funds' => 'Presunúť prostriedky',
    ],

    'glance' => [
        'see_all' => 'Zobraziť všetko →',
    ],

    'notices' => [
        'invalid_amount' => 'Zadaj platnú sumu.',
        'threshold_range' => 'Zadaj celé číslo od 1 do 200.',
        'copied_last_month' => 'Plán z minulého mesiaca skopírovaný.',
        'choose_envelope' => 'Vyber obálku, do ktorej sa majú peniaze presunúť.',
        'amount_positive' => 'Zadaj sumu väčšiu ako nula.',
        'move_failed' => 'Presun sa nepodarilo dokončiť — skús to znova.',
        'money_moved' => 'Peniaze presunuté.',
        'move_undone' => 'Presun vrátený späť.',
    ],

    'errors' => [
        'assigned_negative' => 'Pridelená suma nemôže byť záporná.',
        'invalid_overspend_mode' => 'Neplatný režim prečerpania.',
        'threshold_range' => 'Prah upozornenia musí byť od 1 do 200.',
        'same_envelope' => 'Zdrojová a cieľová obálka musia byť rozdielne.',
        'non_positive_amount' => 'Neplatná alebo nekladná suma.',
        'category_not_found' => 'Kategória sa nenašla alebo k nej používateľ nemá prístup.',
    ],
];
