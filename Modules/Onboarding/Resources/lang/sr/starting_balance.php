<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 POČETNO STANJE',
    'confirmed_aria' => 'potvrđeno',
    'on_date' => 'na dan :date',

    'detected_h3' => 'Otkrili smo početno stanje — :label',
    'confirm' => 'Potvrdi',
    'edit' => 'Izmeni',

    'conflict_h3' => 'Vidimo dve vrednosti za ovaj račun — koja je tačna?',
    'conflict_legend' => 'Izaberi početno stanje',
    'conflict_from' => 'Izvor :source:',
    'conflict_helper' => 'Podrazumevano biramo najraniji datum. Izaberi ispravnu vrednost ili je izmeni ručno.',
    'edit_manually' => 'Izmeni ručno',

    'editing_h3' => 'Izmeni početno stanje — :label',
    'input_label' => 'POČETNO STANJE',
    'minor_units' => '(najmanje jedinice)',
    'on_date_label' => 'NA DAN',
    'cancel' => 'Otkaži',
    'save' => 'Sačuvaj',

    'change' => 'Promeni',

    'manual_h3' => 'Ručno unesi početno stanje — :label',
    'manual_lede' => 'Nismo mogli automatski da otkrijemo početno stanje za ovaj račun. Unesi ga ručno ili preskoči.',

    'unknown_state' => 'Nepoznato stanje kartice. Ponovo učitaj čarobnjak.',

    'errors' => [
        'account_not_set' => 'Račun nije postavljen. Ponovo učitaj čarobnjak.',
        'invalid_amount' => 'Unesi ispravan iznos.',
        'amount_range' => 'Unesi iznos između :min i :max.',
        'pick_date' => 'Izaberi datum.',
        'pick_valid_date' => 'Izaberi ispravan datum.',
        'future_date' => 'Datum početnog stanja ne može biti u budućnosti.',
        'date_warning' => 'Ovo je kasnije od tvoje prve uvezene transakcije (:date). Kontrolna tabla može da prikaže transakcije pre tog datuma.',
    ],
];
