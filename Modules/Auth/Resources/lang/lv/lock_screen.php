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
    'forgot_pin' => 'Aizmirsāt PIN kodu? Atteikties — ja konta parole vēl atver šo bloķēšanu, varat pieteikties atkārtoti, iestatīt jaunu PIN kodu un neko nezaudēt. Parole, kas atiestatīta ar atkopšanas kodu vai ko jums iestatījis konta īpašnieks, to vairs neatver.',

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
];
