<?php

declare(strict_types=1);

return [
    'page_title' => 'Entsperren',

    'digits_entered' => ':count Ziffer eingegeben|:count Ziffern eingegeben',
    'pin_pad' => 'PIN-Tastenfeld',
    'digit' => 'Ziffer :digit',
    'backspace' => 'Rücktaste',
    'ok' => 'OK',
    'ok_aria' => 'OK — PIN bestätigen',
    'sign_out' => 'Abmelden',
    'forgot_pin' => 'PIN vergessen? Melde dich ab — wenn dein Kontopasswort diese Sperre noch öffnet, meldest du dich wieder an, legst eine neue PIN fest und verlierst nichts. Ein Passwort, das mit einem Wiederherstellungscode zurückgesetzt oder dir vom Kontoinhaber gesetzt wurde, öffnet sie nicht mehr.',

    'errors' => [
        'pin_length' => 'Die PIN muss mindestens 6 Ziffern haben.',

        'too_many_attempts' => 'Zu viele Versuche — versuche es in :secondss erneut.',
        'incorrect_pin_remaining' => 'Falsche PIN. Noch :count Versuch übrig.|Falsche PIN. Noch :count Versuche übrig.',
        'incorrect_pin' => 'Falsche PIN.',
    ],
];
