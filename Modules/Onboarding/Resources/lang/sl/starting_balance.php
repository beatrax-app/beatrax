<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 ZAČETNO STANJE',
    'confirmed_aria' => 'potrjeno',
    'on_date' => 'na dan :date',

    'detected_h3' => 'Zaznali smo začetno stanje — :label',
    'confirm' => 'Potrdi',
    'edit' => 'Uredi',

    'conflict_h3' => 'Za ta račun vidimo dve vrednosti — katera je pravilna?',
    'conflict_legend' => 'Izberi začetno stanje',
    'conflict_from' => 'Vir :source:',
    'conflict_helper' => 'Privzeto izberemo najzgodnejši datum. Izberi pravo vrednost ali jo uredi ročno.',
    'edit_manually' => 'Uredi ročno',

    'editing_h3' => 'Uredi začetno stanje — :label',
    'input_label' => 'ZAČETNO STANJE',
    'minor_units' => '(najmanjše enote)',
    'on_date_label' => 'NA DAN',
    'cancel' => 'Prekliči',
    'save' => 'Shrani',

    'change' => 'Spremeni',

    'manual_h3' => 'Ročno vnesi začetno stanje — :label',
    'manual_lede' => 'Začetnega stanja za ta račun nismo mogli samodejno zaznati. Vnesi ga ročno ali preskoči.',

    'unknown_state' => 'Neznano stanje kartice. Znova naloži čarovnika.',

    'errors' => [
        'account_not_set' => 'Račun ni nastavljen. Znova naloži čarovnika.',
        'invalid_amount' => 'Vnesi veljaven znesek.',
        'amount_range' => 'Vnesi znesek med -10 milijoni € in 10 milijoni €.',
        'pick_date' => 'Izberi datum.',
        'pick_valid_date' => 'Izberi veljaven datum.',
        'future_date' => 'Datum začetnega stanja ne more biti v prihodnosti.',
        'date_warning' => 'To je pozneje od tvoje prve uvožene transakcije (:date). Nadzorna plošča lahko prikaže transakcije pred tem datumom.',
    ],
];
