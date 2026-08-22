<?php

declare(strict_types=1);

return [
    'page_title' => 'Atbloķēt',

    'digits_entered' => 'ievadītie cipari',
    'pin_pad' => 'PIN kodu tastatūra',
    'digit' => 'Cipars :digit',
    'backspace' => 'Atpakaļatkāpe',
    'ok' => 'Labi',
    'ok_aria' => 'Labi — apstiprināt PIN kodu',
    'sign_out' => 'Atteikties',
    // i18n-review: lv · forgot_pin — "Atteikties" now matches the button word for
    // word, but the same verb is this app's word for cancelling a subscription
    // (drift-alerts cancel_impact). A native reader should say whether all three
    // sign_out labels want "Izrakstīties" instead.
    'forgot_pin' => 'Aizmirsāt PIN kodu? Atteikties — varat pieteikties atkārtoti ar konta paroli un iestatīt jaunu PIN kodu. Dati netiek zaudēti.',

    'errors' => [
        'pin_length' => 'PIN kodā jābūt vismaz 6 cipariem.',

        'too_many_attempts' => 'Pārāk daudz mēģinājumu — mēģiniet vēlreiz pēc :secondss.',
        'incorrect_pin_remaining' => 'Nepareizs PIN kods. Atlikuši :count mēģinājumu.|Nepareizs PIN kods. Atlicis :count mēģinājums.|Nepareizs PIN kods. Atlikuši :count mēģinājumi.',
        'incorrect_pin' => 'Nepareizs PIN kods.',
    ],
];
