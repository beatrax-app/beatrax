<?php

declare(strict_types=1);

return [
    'native_unlock_reason' => 'Отключи Beatrax',
    'native_unlock_failed' => 'Отключването не бе успешно. Въведи ПИН кода си вместо това.',
    'page_title' => 'Отключване · Beatrax',
    'sign_out' => 'Изход',
    // i18n-review: bg · forgot_pin — the sentence says "Излез" and the button says
    // "Изход". Same root, and twenty other locales pair an imperative with a noun
    // label the same way, but a native should confirm the pairing reads.
    'forgot_pin' => 'Забрави ли ПИН кода? Излез — ако паролата за профила ти още отваря това заключване, можеш да влезеш отново, да зададеш нов ПИН код и да не загубиш нищо. Парола, нулирана с код за възстановяване или зададена ти от собственика на профила, вече не го отваря.',

    'digits_entered' => ':count въведена цифра|:count въведени цифри',
    'pad_label' => 'Клавиатура за ПИН',
    'digit_aria' => 'Цифра :digit',
    'backspace_aria' => 'Изтриване назад',
    'ok_aria' => 'OK — потвърди ПИН кода',
    'ok' => 'OK',

    'error_pin_shape' => 'ПИН кодът трябва да е от :min до :max цифри — само цифри.',

    'error_backoff' => 'Твърде много опити — опитай отново след :wait.',

    'error_incorrect_remaining' => 'Грешен ПИН код. Остава ти :count опит.|Грешен ПИН код. Остават ти :count опита.',
    'error_incorrect' => 'Грешен ПИН код.',
];
