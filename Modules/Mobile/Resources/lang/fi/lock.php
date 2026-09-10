<?php

declare(strict_types=1);

return [
    'page_title' => 'Avaa lukitus',

    'digits_entered' => 'syötetty :count numero|syötetty :count numeroa',
    'pin_pad' => 'PIN-näppäimistö',
    'digit' => 'Numero :digit',
    'backspace' => 'Askelpalautin',
    'ok' => 'OK',
    'ok_aria' => 'OK — vahvista PIN-koodi',
    'sign_out' => 'Kirjaudu ulos',
    'forgot_pin' => 'Unohditko PIN-koodin? Kirjaudu ulos',

    'errors' => [
        'pin_length' => 'PIN-koodissa on oltava vähintään 6 numeroa.',

        'too_many_attempts' => 'Liikaa yrityksiä — yritä uudelleen :secondss kuluttua.',
        'incorrect_pin_remaining' => 'Väärä PIN-koodi. :count yritys jäljellä.|Väärä PIN-koodi. :count yritystä jäljellä.',
        'incorrect_pin' => 'Väärä PIN-koodi.',

        'pin_changed' => 'Tämän laitteen PIN-koodi vaihdettiin avaamisen aikana. Anna nykyinen PIN-koodi.',

        'biometric_reset' => 'Biometrinen avaus nollattiin. Anna PIN-koodi ja ota se sitten uudelleen käyttöön kohdassa Sovelluslukko.',
    ],
];
