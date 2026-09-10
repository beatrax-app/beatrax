<?php

declare(strict_types=1);

return [
    'page_title' => 'Розблокування',

    'digits_entered' => 'введено :count цифру|введено :count цифри|введено :count цифр',
    'pin_pad' => 'PIN-клавіатура',
    'digit' => 'Цифра :digit',
    'backspace' => 'Стерти',
    'ok' => 'OK',
    'ok_aria' => 'OK — підтвердити PIN',
    'sign_out' => 'Вийти',
    'forgot_pin' => 'Не пам’ятаєш PIN? Вийди',

    'errors' => [
        'pin_length' => 'PIN має містити щонайменше 6 цифри.',

        'too_many_attempts' => 'Забагато спроб — повтори через :secondsс.',
        'incorrect_pin_remaining' => 'Неправильний PIN. Залишилася :count спроба.|Неправильний PIN. Залишилося :count спроби.|Неправильний PIN. Залишилося :count спроб.',
        'incorrect_pin' => 'Неправильний PIN.',

        'pin_changed' => 'PIN для цього пристрою було змінено під час розблокування. Введи поточний PIN.',

        'biometric_reset' => 'Біометричне розблокування було скинуто. Введи PIN, а потім знову ввімкни його в розділі Блокування застосунку.',
    ],
];
