<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 POČETNO STANJE',
    'confirmed_aria' => 'potvrđeno',
    'on_date' => 'na dan :date',

    'detected_h3' => 'Otkrili smo početno stanje — :label',
    'confirm' => 'Potvrdi',
    'edit' => 'Uredi',

    'conflict_h3' => 'Vidimo dvije vrijednosti za ovaj račun — koja je točna?',
    'conflict_legend' => 'Odaberi početno stanje',
    'conflict_from' => 'Izvor :source:',
    'conflict_helper' => 'Zadano biramo najraniji datum. Odaberi ispravnu vrijednost ili je uredi ručno.',
    'edit_manually' => 'Uredi ručno',

    'editing_h3' => 'Uredi početno stanje — :label',
    'input_label' => 'POČETNO STANJE',
    'minor_units' => '(najmanje jedinice)',
    'on_date_label' => 'NA DAN',
    'cancel' => 'Odustani',
    'save' => 'Spremi',

    'change' => 'Promijeni',

    'manual_h3' => 'Ručno unesi početno stanje — :label',
    'manual_lede' => 'Nismo mogli automatski otkriti početno stanje za ovaj račun. Unesi ga ručno ili preskoči.',

    'unknown_state' => 'Nepoznato stanje kartice. Ponovno učitaj čarobnjak.',

    'errors' => [
        'account_not_set' => 'Račun nije postavljen. Ponovno učitaj čarobnjak.',
        'invalid_amount' => 'Unesi ispravan iznos.',
        'amount_range' => 'Unesi iznos između -10 milijuna € i 10 milijuna €.',
        'pick_date' => 'Odaberi datum.',
        'pick_valid_date' => 'Odaberi ispravan datum.',
        'future_date' => 'Datum početnog stanja ne može biti u budućnosti.',
        'date_warning' => 'Ovo je kasnije od tvoje prve uvezene transakcije (:date). Nadzorna ploča može prikazati transakcije prije tog datuma.',
    ],
];
