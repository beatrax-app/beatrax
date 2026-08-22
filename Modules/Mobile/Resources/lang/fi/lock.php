<?php

declare(strict_types=1);

return [
    'page_title' => 'Avaa lukitus',

    'digits_entered' => 'numeroa syötetty',
    'pin_pad' => 'PIN-näppäimistö',
    'digit' => 'Numero :digit',
    'backspace' => 'Askelpalautin',
    'ok' => 'OK',
    'ok_aria' => 'OK — vahvista PIN-koodi',
    'sign_out' => 'Kirjaudu ulos',
    // i18n-review: fi · forgot_pin — "Tietoja ei häviä" is grammatical, with the
    // partitive the negation wants. Whether a Finnish reader would sooner see
    // "Tietoja ei katoa" or "Tiedot säilyvät" is the open question.
    'forgot_pin' => 'Unohditko PIN-koodin? Kirjaudu ulos — voit kirjautua takaisin sisään tilisi salasanalla ja asettaa uuden PIN-koodin. Tietoja ei häviä.',

    'errors' => [
        'pin_length' => 'PIN-koodissa on oltava vähintään 6 numeroa.',

        'too_many_attempts' => 'Liikaa yrityksiä — yritä uudelleen :secondss kuluttua.',
        'incorrect_pin_remaining' => 'Väärä PIN-koodi. :count yritys jäljellä.|Väärä PIN-koodi. :count yritystä jäljellä.',
        'incorrect_pin' => 'Väärä PIN-koodi.',
    ],
];
