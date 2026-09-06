<?php

declare(strict_types=1);

return [
    'page_title' => 'Ava',

    'digits_entered' => 'sisestatud :count number|sisestatud :count numbrit',
    'pin_pad' => 'PIN-klaviatuur',
    'digit' => 'Number :digit',
    'backspace' => 'Tagasilüke',
    'ok' => 'OK',
    'ok_aria' => 'OK — kinnita PIN-kood',
    'sign_out' => 'Logi välja',
    // i18n-review: et · forgot_pin — "Andmeid ei lähe kaotsi" is grammatical and
    // the negation takes the partitive correctly, but "kaotsi minema" is usually
    // said of objects; whether a native would write "ei lähe kaduma" is open.
    'forgot_pin' => 'Unustasid PIN-koodi? Logi välja — kui konto parool selle luku veel avab, saad uuesti sisse logida, uue PIN-koodi määrata ja midagi ei lähe kaotsi. Taastekoodiga lähtestatud või konto omaniku poolt sinu eest määratud parool seda enam ei ava.',

    'errors' => [
        'pin_length' => 'PIN-kood peab olema vähemalt 6 numbrit.',

        'too_many_attempts' => 'Liiga palju katseid — proovi uuesti :secondss pärast.',
        'incorrect_pin_remaining' => 'Vale PIN-kood. Jäänud on :count katse.|Vale PIN-kood. Jäänud on :count katset.',
        'incorrect_pin' => 'Vale PIN-kood.',
    ],
];
