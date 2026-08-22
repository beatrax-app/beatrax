<?php

declare(strict_types=1);

return [
    'page_title' => 'Feloldás',

    'digits_entered' => 'számjegy megadva',
    'pin_pad' => 'PIN-billentyűzet',
    'digit' => 'Számjegy :digit',
    'backspace' => 'Visszatörlés',
    'ok' => 'OK',
    'ok_aria' => 'OK — PIN-kód megerősítése',
    'sign_out' => 'Kijelentkezés',
    // i18n-review: hu · forgot_pin — "Nem vész el adat" matches the same clause in
    // app_lock forgot_modal_body, so it is at least consistent; standing alone
    // without "soha" it is terse, and a native may want "Semmilyen adat nem vész
    // el" instead.
    'forgot_pin' => 'Elfelejtetted a PIN-kódot? Jelentkezz ki — a fiókjelszavaddal újra bejelentkezhetsz, és beállíthatsz egy új PIN-kódot. Nem vész el adat.',

    'errors' => [
        'pin_length' => 'A PIN-kódnak legalább 6 számjegyből kell állnia.',

        'too_many_attempts' => 'Túl sok próbálkozás — próbáld újra :secondsmp múlva.',
        'incorrect_pin_remaining' => 'Hibás PIN-kód. Még :count próbálkozás maradt.|Hibás PIN-kód. Még :count próbálkozás maradt.',
        'incorrect_pin' => 'Hibás PIN-kód.',
    ],
];
