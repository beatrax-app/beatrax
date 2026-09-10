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
    'forgot_pin' => 'Elfelejtetted a PIN-kódot? Jelentkezz ki',

    'errors' => [
        'pin_length' => 'A PIN-kódnak legalább 6 számjegyből kell állnia.',

        'too_many_attempts' => 'Túl sok próbálkozás — próbáld újra :secondsmp múlva.',
        'incorrect_pin_remaining' => 'Hibás PIN-kód. Még :count próbálkozás maradt.|Hibás PIN-kód. Még :count próbálkozás maradt.',
        'incorrect_pin' => 'Hibás PIN-kód.',

        'pin_changed' => 'Ennek az eszköznek a PIN-kódja megváltozott a feloldás közben. Add meg az aktuális PIN-kódot.',
    ],
];
