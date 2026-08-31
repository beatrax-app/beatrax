<?php

declare(strict_types=1);

return [
    'native_unlock_reason' => 'A Beatrax feloldása',
    'native_unlock_failed' => 'A feloldás nem sikerült. Add meg inkább a PIN-kódot.',
    'page_title' => 'Feloldás · Beatrax',
    'sign_out' => 'Kijelentkezés',
    // i18n-review: hu · forgot_pin — "Nem vész el adat" matches the same clause in
    // app_lock forgot_modal_body, so it is at least consistent; standing alone
    // without "soha" it is terse, and a native may want "Semmilyen adat nem vész
    // el" instead.
    'forgot_pin' => 'Elfelejtetted a PIN-kódot? Jelentkezz ki — a fiókjelszavaddal újra bejelentkezhetsz, és beállíthatsz egy új PIN-kódot. Nem vész el adat.',

    'digits_entered' => ':count számjegy beírva|:count számjegy beírva',
    'pad_label' => 'PIN-billentyűzet',
    'digit_aria' => ':digit számjegy',
    'backspace_aria' => 'Visszatörlés',
    'ok_aria' => 'OK — PIN-kód megerősítése',
    'ok' => 'OK',

    'error_pin_shape' => 'A PIN-kódnak :min–:max számjegyből kell állnia — csak számok.',

    'error_backoff' => 'Túl sok próbálkozás — próbáld újra ennyi múlva: :wait.',

    'error_incorrect_remaining' => 'Hibás PIN-kód. Még :count próbálkozásod maradt.|Hibás PIN-kód. Még :count próbálkozásod maradt.',
    'error_incorrect' => 'Hibás PIN-kód.',
];
