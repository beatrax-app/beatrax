<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Budjetit',
        'subtitle' => 'Jaa kaikki — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Edellinen jakso',
        'next_aria' => 'Seuraava jakso',
    ],

    'ready' => [
        'label' => 'Jaettavissa',
        'overassigned' => 'Olet jakanut enemmän kuin sinulla on — pienennä jotain kuorta tai odota lisää tuloja.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Mitään ei ole vielä jaettu',
        'copy_hint' => 'Kopioi viime kuun suunnitelma tai aloita jakaminen napsauttamalla alla olevaa solua.',
        'first_hint' => 'Aloita ensimmäisen kuukauden jakaminen napsauttamalla alla olevaa solua.',
        'copy_button' => 'Kopioi viime kuu',
    ],

    'no_categories' => [
        'heading' => 'Ei vielä menokategorioita',
        'body' => 'Lisää menokategoria, jotta voit jakaa sille rahaa.',
    ],

    'table' => [
        'category' => 'Kategoria',
        'assigned' => 'Jaettu',
        'carried_in' => 'Siirtosaldo',
        'moved' => 'Siirretty',
        'spent' => 'Käytetty',
        'available' => 'Käytettävissä',
        'if_overspent' => 'Jos ylitetään',
        'notify_at' => 'Ilmoita rajalla',
        'actions' => 'Toiminnot',
    ],

    'badge' => [
        'carries_negative' => 'Siirtää miinuksen',
        'unconverted_aria' => 'Valuutassa, jolle ei ole kurssia, tehtyjä menoja ei lasketa tähän — katso koontinäyttö',
        'unconverted_title' => 'Menot ilman kurssia eivät sisälly tähän — katso koontinäyttö',
        'over_budget' => ':count yli budjetin',
    ],

    'row' => [
        'assigned_aria' => 'Jaettu kategorialle :category',
        'overspend_aria' => 'Jos kategoria :category ylitetään',
        'notify_aria' => 'Ilmoita minulle käytetyn prosenttiosuuden rajalla kategoriassa :category',
        'move_money' => 'Siirrä rahaa',
        'move' => 'Siirrä',
    ],

    'overspend' => [
        'reduce' => 'Pienennä ensi kuun jaettavissa olevaa summaa',
        'carry' => 'Siirrä miinus tähän kuoreen',
    ],

    'history' => [
        'show' => 'Näytä historia ↓',
        'hide' => 'Piilota historia ↑',
        'moved_from' => 'Siirretty kategoriasta :category',
        'moved_to' => 'Siirretty kategoriaan :category',
        'moved_unreadable' => 'Siirretty kategorian :category kanssa Beatraxin uudemmalla versiolla',
        'undo' => 'Kumoa',
    ],

    'phone' => [
        'spent' => 'Käytetty :amount',
        'carried_in' => 'Siirtosaldo :amount',
        'moved' => 'Siirretty :amount',
        'available' => 'Käytettävissä :amount',
        'notify_at' => 'Ilmoita rajalla',
    ],

    'modal' => [
        'move_from' => 'Siirrä kuoresta :name',
        'move_from_fallback' => 'kuori',
        'move_to' => 'Siirrä kuoreen',
        'no_other' => 'Ei muita kuoria',
        'select' => 'Valitse kuori',
        'amount' => 'Summa',
        'available_in' => 'Käytettävissä kuoressa :name: :amount',
        'note' => 'Muistiinpano (valinnainen)',
        'note_placeholder' => 'esim. Kattaa ravintolamenojen ylityksen',
        'cancel' => 'Peruuta',
        'move_funds' => 'Siirrä varat',
    ],

    'glance' => [
        'see_all' => 'Näytä kaikki →',
    ],

    'notices' => [
        'invalid_amount' => 'Anna kelvollinen summa.',
        'threshold_range' => 'Anna kokonaisluku väliltä 1–200.',
        'copied_last_month' => 'Viime kuun suunnitelma kopioitu.',
        'choose_envelope' => 'Valitse kuori, johon rahat siirretään.',
        'amount_positive' => 'Anna nollaa suurempi summa.',
        'move_failed' => 'Siirtoa ei voitu tehdä — yritä uudelleen.',
        'money_moved' => 'Rahat siirretty.',
        'move_undone' => 'Siirto kumottu.',
    ],

    'errors' => [
        'assigned_negative' => 'Jaettu summa ei voi olla negatiivinen.',
        'invalid_overspend_mode' => 'Virheellinen ylitystila.',
        'threshold_range' => 'Ilmoitusrajan on oltava välillä 1–200.',
        'same_envelope' => 'Lähde- ja kohdekuoren on oltava eri.',
        'non_positive_amount' => 'Virheellinen tai nollaa pienempi summa.',
        'category_not_found' => 'Kategoriaa ei löytynyt tai se ei ole käyttäjän käytettävissä.',
    ],
];
