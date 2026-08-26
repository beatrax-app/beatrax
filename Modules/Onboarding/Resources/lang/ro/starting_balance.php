<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 SOLD INIȚIAL',
    'confirmed_aria' => 'confirmat',
    'on_date' => 'la :date',

    'detected_h3' => 'Am detectat că :label a pornit de la',
    'confirm' => 'Confirmă',
    'edit' => 'Editează',

    'conflict_h3' => 'Am văzut două valori pentru acest cont — care e corectă?',
    'conflict_legend' => 'Alege un sold inițial',
    'conflict_from' => 'Din :source:',
    'conflict_helper' => 'Implicit alegem cea mai veche dată. Alege varianta corectă sau editează manual.',
    'edit_manually' => 'Editează manual',

    'editing_h3' => 'Editează soldul inițial pentru :label',
    'input_label' => 'SOLD INIȚIAL',
    'minor_units' => '(unități minore)',
    'on_date_label' => 'LA DATA',
    'cancel' => 'Anulează',
    'save' => 'Salvează',

    'change' => 'Schimbă',

    'manual_h3' => 'Introdu manual soldul inițial pentru :label',
    'manual_lede' => 'Nu am putut detecta automat un sold inițial pentru acest cont. Introdu unul manual sau omite pasul.',

    'unknown_state' => 'Stare necunoscută a cardului. Reîncarcă asistentul.',

    'errors' => [
        'account_not_set' => 'Contul nu este setat. Reîncarcă asistentul.',
        'invalid_amount' => 'Introdu o sumă validă.',
        'amount_range' => 'Introdu o sumă între :min și :max.',
        'pick_date' => 'Alege o dată.',
        'pick_valid_date' => 'Alege o dată validă.',
        'future_date' => 'Data soldului inițial nu poate fi în viitor.',
        'date_warning' => 'Este mai târziu decât prima ta tranzacție importată (:date). Tabloul de bord poate afișa tranzacții dinainte de această dată.',
    ],
];
