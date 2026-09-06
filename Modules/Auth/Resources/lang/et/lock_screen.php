<?php

declare(strict_types=1);

return [
    'native_unlock_reason' => 'Ava Beatrax',
    'native_unlock_failed' => 'Avamine ebaõnnestus. Sisesta selle asemel PIN-kood.',
    'page_title' => 'Ava · Beatrax',
    'sign_out' => 'Logi välja',
    // i18n-review: et · forgot_pin — "Andmeid ei lähe kaotsi" is grammatical and
    // the negation takes the partitive correctly, but "kaotsi minema" is usually
    // said of objects; whether a native would write "ei lähe kaduma" is open.
    'forgot_pin' => 'Unustasid PIN-koodi? Logi välja — kui konto parool selle luku veel avab, saad uuesti sisse logida, uue PIN-koodi määrata ja midagi ei lähe kaotsi. Taastekoodiga lähtestatud või konto omaniku poolt sinu eest määratud parool seda enam ei ava.',

    'digits_entered' => ':count number sisestatud|:count numbrit sisestatud',
    'pad_label' => 'PIN-klaviatuur',
    'digit_aria' => 'Number :digit',
    'backspace_aria' => 'Tagasilüke',
    'ok_aria' => 'OK — kinnita PIN-kood',
    'ok' => 'OK',

    'error_pin_shape' => 'PIN-kood peab olema :min–:max numbrit — ainult numbrid.',

    'error_backoff' => 'Liiga palju katseid — proovi uuesti :wait pärast.',

    'error_incorrect_remaining' => 'Vale PIN-kood. Jäänud on :count katse.|Vale PIN-kood. Jäänud on :count katset.',
    'error_incorrect' => 'Vale PIN-kood.',
];
