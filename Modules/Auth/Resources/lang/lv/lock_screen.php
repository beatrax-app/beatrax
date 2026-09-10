<?php

declare(strict_types=1);

return [
    'native_unlock_reason' => 'Atbloķēt Beatrax',
    'native_unlock_failed' => 'Neizdevās atbloķēt. Ievadiet PIN kodu.',
    'native_unlock_reset' => 'Biometriskā atbloķēšana tika atiestatīta. Ievadiet PIN kodu un pēc tam ieslēdziet to atkal sadaļā Lietotnes bloķēšana.',
    'page_title' => 'Atbloķēt · Beatrax',
    'sign_out' => 'Atteikties',
    // i18n-review: lv · forgot_pin — "Atteikties" now matches the button word for
    // word, but the same verb is this app's word for cancelling a subscription
    // (drift-alerts cancel_impact). A native reader should say whether all three
    // sign_out labels want "Izrakstīties" instead.
    'forgot_pin' => 'Aizmirsāt PIN kodu? Atteikties',

    'digits_entered' => '{0} ievadīti :count ciparu|{1} ievadīts :count cipars|[2,*] ievadīti :count cipari',
    'pad_label' => 'PIN koda tastatūra',
    'digit_aria' => 'Cipars :digit',
    'backspace_aria' => 'Atpakaļatkāpe',
    'ok_aria' => 'Labi — apstiprināt PIN kodu',
    'ok' => 'Labi',

    'error_pin_shape' => 'PIN kodā jābūt :min līdz :max cipariem — tikai cipari.',

    'error_backoff' => 'Pārāk daudz mēģinājumu — mēģiniet vēlreiz pēc :wait.',

    'error_incorrect_remaining' => 'Nepareizs PIN kods. Atlikuši :count mēģinājumu.|Nepareizs PIN kods. Atlicis :count mēģinājums.|Nepareizs PIN kods. Atlikuši :count mēģinājumi.',
    'error_incorrect' => 'Nepareizs PIN kods.',

    'error_pin_changed' => 'Šīs ierīces PIN kods tika mainīts atbloķēšanas laikā. Ievadiet pašreizējo PIN kodu.',
];
