<?php

declare(strict_types=1);

return [
    'native_unlock_reason' => 'Atbloķēt Beatrax',
    'native_unlock_failed' => 'Neizdevās atbloķēt. Ievadiet PIN kodu.',
    'page_title' => 'Atbloķēt · Beatrax',
    'sign_out' => 'Atteikties',
    // i18n-review: lv · forgot_pin — "Atteikties" now matches the button word for
    // word, but the same verb is this app's word for cancelling a subscription
    // (drift-alerts cancel_impact). A native reader should say whether all three
    // sign_out labels want "Izrakstīties" instead.
    'forgot_pin' => 'Aizmirsāt PIN kodu? Atteikties — varat pieteikties atkārtoti ar konta paroli un iestatīt jaunu PIN kodu. Dati netiek zaudēti.',

    'digits_suffix' => 'ievadīti cipari',
    'pad_label' => 'PIN koda tastatūra',
    'digit_aria' => 'Cipars :digit',
    'backspace_aria' => 'Atpakaļatkāpe',
    'ok_aria' => 'Labi — apstiprināt PIN kodu',
    'ok' => 'Labi',

    'error_too_short' => 'PIN kodā jābūt vismaz 6 cipariem.',

    'error_backoff' => 'Pārāk daudz mēģinājumu — mēģiniet vēlreiz pēc :wait.',

    'error_incorrect_remaining' => 'Nepareizs PIN kods. Atlikuši :count mēģinājumu.|Nepareizs PIN kods. Atlicis :count mēģinājums.|Nepareizs PIN kods. Atlikuši :count mēģinājumi.',
    'error_incorrect' => 'Nepareizs PIN kods.',
];
