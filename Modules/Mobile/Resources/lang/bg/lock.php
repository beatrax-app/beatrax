<?php

declare(strict_types=1);

return [
    'page_title' => 'Отключване',

    'digits_entered' => 'въведени цифри',
    'pin_pad' => 'ПИН клавиатура',
    'digit' => 'Цифра :digit',
    'backspace' => 'Изтриване назад',
    'ok' => 'OK',
    'ok_aria' => 'OK — потвърди ПИН кода',
    'sign_out' => 'Изход',
    // i18n-review: bg · forgot_pin — the sentence says "Излез" and the button says
    // "Изход". Same root, and twenty other locales pair an imperative with a noun
    // label the same way, but a native should confirm the pairing reads.
    'forgot_pin' => 'Забрави ли ПИН кода? Излез — можеш да влезеш отново с паролата за профила и да зададеш нов ПИН код. Не се губят данни.',

    'errors' => [
        'pin_length' => 'ПИН кодът трябва да е поне 6 цифри.',

        'too_many_attempts' => 'Твърде много опити — опитай отново след :secondsс.',
        'incorrect_pin_remaining' => 'Грешен ПИН код. Остава :count опит.|Грешен ПИН код. Остават :count опита.',
        'incorrect_pin' => 'Грешен ПИН код.',
    ],
];
