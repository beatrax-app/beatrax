<?php

declare(strict_types=1);

return [
    'page_title' => 'Feloldás',

    'digits_entered' => ':count számjegy megadva|:count számjegy megadva',
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
    'forgot_pin' => 'Elfelejtetted a PIN-kódot? Jelentkezz ki — ha a fiókjelszavad még nyitja ezt a zárat, újra bejelentkezhetsz, beállíthatsz egy új PIN-kódot, és semmi nem vész el. Az a jelszó, amelyet helyreállítási kóddal állítottál vissza, vagy amelyet a fiók tulajdonosa állított be neked, már nem nyitja.',

    'errors' => [
        'pin_length' => 'A PIN-kódnak legalább 6 számjegyből kell állnia.',

        'too_many_attempts' => 'Túl sok próbálkozás — próbáld újra :secondsmp múlva.',
        'incorrect_pin_remaining' => 'Hibás PIN-kód. Még :count próbálkozás maradt.|Hibás PIN-kód. Még :count próbálkozás maradt.',
        'incorrect_pin' => 'Hibás PIN-kód.',
    ],
];
