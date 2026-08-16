<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Proračuni',
        'subtitle' => 'Rasporedi svaki euro — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Prethodno razdoblje',
        'next_aria' => 'Sljedeće razdoblje',
    ],

    'ready' => [
        'label' => 'Spremno za raspoređivanje',
        'overassigned' => 'Raspoređeno je više nego što imaš — smanji neku omotnicu ili pričekaj nove prihode.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Još ništa nije raspoređeno',
        'copy_hint' => 'Kopiraj plan prošlog mjeseca ili klikni na ćeliju ispod i počni raspoređivati.',
        'first_hint' => 'Klikni na ćeliju ispod i počni raspoređivati svoj prvi mjesec.',
        'copy_button' => 'Kopiraj prošli mjesec',
    ],

    'no_categories' => [
        'heading' => 'Još nema kategorija troškova',
        'body' => 'Dodaj kategoriju troškova i počni joj raspoređivati novac.',
    ],

    'table' => [
        'category' => 'Kategorija',
        'assigned' => 'Raspoređeno',
        'spent' => 'Potrošeno',
        'available' => 'Dostupno',
        'if_overspent' => 'Ako se prekorači',
        'notify_at' => 'Obavijesti pri',
        'actions' => 'Radnje',
    ],

    'badge' => [
        'carries_negative' => 'Prenosi minus',
        'non_eur_aria' => 'Potrošnja izvan EUR u ovoj kategoriji ovdje nije prikazana — pogledaj nadzornu ploču',
        'non_eur_title' => 'Potrošnja izvan EUR ovdje nije prikazana — pogledaj nadzornu ploču',
        'over_budget' => ':count preko proračuna',
    ],

    'row' => [
        'assigned_aria' => 'Raspoređeno za :category',
        'overspend_aria' => 'Ako je :category prekoračena',
        'notify_aria' => 'Obavijesti me pri postotku iskorištenosti za :category',
        'move_money' => 'Premjesti novac',
        'move' => 'Premjesti',
    ],

    'overspend' => [
        'reduce' => 'Smanji iznos spreman za raspoređivanje sljedećeg mjeseca',
        'carry' => 'Prenesi minus u ovoj omotnici',
    ],

    'history' => [
        'show' => 'Prikaži povijest ↓',
        'hide' => 'Sakrij povijest ↑',
        'moved_from' => 'Premješteno iz :category',
        'moved_to' => 'Premješteno u :category',
        'undo' => 'Poništi',
    ],

    'phone' => [
        'spent' => 'Potrošeno :amount',
        'available' => 'Dostupno :amount',
        'notify_at' => 'Obavijesti pri',
    ],

    'modal' => [
        'move_from' => 'Premjesti iz :name',
        'move_from_fallback' => 'omotnica',
        'move_to' => 'Premjesti u',
        'no_other' => 'Nema drugih omotnica',
        'select' => 'Odaberi omotnicu',
        'amount' => 'Iznos',
        'available_in' => 'Dostupno u :name: :amount',
        'note' => 'Bilješka (neobavezno)',
        'note_placeholder' => 'npr. Pokrivanje prekoračenja za restorane',
        'cancel' => 'Odustani',
        'move_funds' => 'Premjesti sredstva',
    ],

    'glance' => [
        'see_all' => 'Prikaži sve →',
    ],

    'notices' => [
        'invalid_amount' => 'Unesi valjani iznos.',
        'threshold_range' => 'Unesi cijeli broj između 1 i 200.',
        'copied_last_month' => 'Plan prošlog mjeseca je kopiran.',
        'choose_envelope' => 'Odaberi omotnicu u koju ćeš premjestiti novac.',
        'amount_positive' => 'Unesi iznos veći od nule.',
        'move_failed' => 'Premještanje nije uspjelo — pokušaj ponovno.',
        'money_moved' => 'Novac je premješten.',
        'move_undone' => 'Premještanje je poništeno.',
    ],

    'errors' => [
        'assigned_negative' => 'Raspoređeni iznos ne može biti negativan.',
        'invalid_overspend_mode' => 'Neispravan način obrade prekoračenja.',
        'threshold_range' => 'Prag obavijesti mora biti između 1 i 200.',
        'same_envelope' => 'Izvorna i odredišna omotnica moraju biti različite.',
        'non_positive_amount' => 'Neispravan ili nepozitivan iznos.',
        'category_not_found' => 'Kategorija nije pronađena ili joj korisnik nema pristup.',
    ],
];
