<?php

declare(strict_types=1);

return [
    'native_unlock_reason' => 'Avaa Beatrax',
    'native_unlock_failed' => 'Avaaminen ei onnistunut. Anna PIN-koodi.',
    'page_title' => 'Avaa lukitus · Beatrax',
    'sign_out' => 'Kirjaudu ulos',
    // i18n-review: fi · forgot_pin — "Tietoja ei häviä" is grammatical, with the
    // partitive the negation wants. Whether a Finnish reader would sooner see
    // "Tietoja ei katoa" or "Tiedot säilyvät" is the open question.
    'forgot_pin' => 'Unohditko PIN-koodin? Kirjaudu ulos — voit kirjautua takaisin sisään tilisi salasanalla ja asettaa uuden PIN-koodin. Tietoja ei häviä.',

    'digits_entered' => ':count numero syötetty|:count numeroa syötetty',
    'pad_label' => 'PIN-näppäimistö',
    'digit_aria' => 'Numero :digit',
    'backspace_aria' => 'Askelpalautin',
    'ok_aria' => 'OK — vahvista PIN-koodi',
    'ok' => 'OK',

    'error_pin_shape' => 'PIN-koodissa on oltava :min–:max numeroa — vain numeroita.',

    'error_backoff' => 'Liikaa yrityksiä — yritä uudelleen :wait kuluttua.',

    'error_incorrect_remaining' => 'Väärä PIN-koodi. :count yritys jäljellä.|Väärä PIN-koodi. :count yritystä jäljellä.',
    'error_incorrect' => 'Väärä PIN-koodi.',
];
