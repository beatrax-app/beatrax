<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Biudžetai',
        'subtitle' => 'Paskirstyk viską — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Ankstesnis laikotarpis',
        'next_aria' => 'Kitas laikotarpis',
    ],

    'ready' => [
        'label' => 'Galima paskirstyti',
        'overassigned' => 'Paskirstei daugiau, nei turi — sumažink voką arba palauk daugiau pajamų.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Kol kas nieko nepaskirstyta',
        'copy_hint' => 'Nukopijuok praėjusio mėnesio planą arba spustelėk langelį žemiau ir pradėk paskirstyti.',
        // i18n-review: lt · empty.copy_hint_touch — the same line for a
        // touch screen; check the verb governs this case.
        'copy_hint_touch' => 'Nukopijuok praėjusio mėnesio planą arba palieski langelį žemiau ir pradėk paskirstyti.',
        'first_hint' => 'Spustelėk langelį žemiau ir pradėk paskirstyti pirmąjį mėnesį.',
        // i18n-review: lt · empty.first_hint_touch — the same line for a
        // touch screen; check the verb governs this case.
        'first_hint_touch' => 'Palieski langelį žemiau ir pradėk paskirstyti pirmąjį mėnesį.',
        'copy_button' => 'Kopijuoti praėjusį mėnesį',
    ],

    'no_categories' => [
        'heading' => 'Kol kas nėra išlaidų kategorijų',
        'body' => 'Pridėk išlaidų kategoriją, kad galėtum jai skirti pinigų.',
    ],

    'table' => [
        'category' => 'Kategorija',
        'assigned' => 'Paskirstyta',
        'carried_in' => 'Pernešta',
        'moved' => 'Perkelta',
        'spent' => 'Išleista',
        'available' => 'Likę',
        'if_overspent' => 'Jei viršyta',
        'notify_at' => 'Pranešti, kai',
        'actions' => 'Veiksmai',
    ],

    'badge' => [
        'carries_negative' => 'Perkelia minusą',
        'unconverted_aria' => 'Išlaidos valiuta, kuriai nėra kurso, čia neskaičiuojamos — žiūrėk skydelį',
        'unconverted_title' => 'Išlaidos be kurso čia neskaičiuojamos — žiūrėk skydelį',
        'over_budget' => 'Viršyta vokų: :count',
    ],

    'row' => [
        'assigned_aria' => 'Paskirstyta kategorijai :category',
        'overspend_aria' => 'Jei kategorija :category viršyta',
        'notify_aria' => 'Pranešti man, kai kategorijoje :category panaudota procentų',
        'move_money' => 'Perkelti pinigus',
        'move' => 'Perkelti',
    ],

    'overspend' => [
        'reduce' => 'Sumažinti kito mėnesio paskirstytiną sumą',
        'carry' => 'Perkelti minusą šiame voke',
    ],

    'history' => [
        'show' => 'Rodyti istoriją ↓',
        'hide' => 'Slėpti istoriją ↑',
        'moved_from' => 'Perkelta iš :category',
        'moved_to' => 'Perkelta į :category',
        'moved_unreadable' => 'Perkelta su :category naujesne Beatrax versija',
        'undo' => 'Anuliuoti',
    ],

    'phone' => [
        'spent' => 'Išleista :amount',
        'carried_in' => 'Pernešta :amount',
        'moved' => 'Perkelta :amount',
        'available' => 'Likę :amount',
        'notify_at' => 'Pranešti, kai',
    ],

    'modal' => [
        'move_from' => 'Perkelti iš :name',
        'move_from_fallback' => 'vokas',
        'move_to' => 'Perkelti į',
        'no_other' => 'Kitų vokų nėra',
        'select' => 'Pasirink voką',
        'amount' => 'Suma',
        'available_in' => 'Likę voke :name: :amount',
        'note' => 'Pastaba (neprivaloma)',
        'note_placeholder' => 'pvz. Padengiamos viršytos maitinimo išlaidos',
        'cancel' => 'Atšaukti',
        'move_funds' => 'Perkelti lėšas',
    ],

    'glance' => [
        'see_all' => 'Žiūrėti visus →',
    ],

    'notices' => [
        'invalid_amount' => 'Įvesk tinkamą sumą.',
        'threshold_range' => 'Įvesk sveikąjį skaičių nuo 1 iki 200.',
        'copied_last_month' => 'Praėjusio mėnesio planas nukopijuotas.',
        'choose_envelope' => 'Pasirink voką, į kurį perkelti pinigus.',
        'amount_positive' => 'Įvesk už nulį didesnę sumą.',
        'move_failed' => 'Nepavyko atlikti perkėlimo — bandyk dar kartą.',
        'money_moved' => 'Pinigai perkelti.',
        'move_undone' => 'Perkėlimas anuliuotas.',
    ],

    'errors' => [
        'assigned_negative' => 'Paskirstyta suma negali būti neigiama.',
        'invalid_overspend_mode' => 'Netinkamas viršijimo režimas.',
        'threshold_range' => 'Pranešimo riba turi būti nuo 1 iki 200.',
        'same_envelope' => 'Šaltinio ir paskirties vokai turi skirtis.',
        'non_positive_amount' => 'Netinkama arba ne teigiama suma.',
        'category_not_found' => 'Kategorija nerasta arba naudotojui neprieinama.',
    ],
];
