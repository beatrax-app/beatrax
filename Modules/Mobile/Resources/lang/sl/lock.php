<?php

declare(strict_types=1);

return [
    'page_title' => 'Odklepanje',

    'digits_entered' => 'vnesena :count števka|vneseni :count števki|vnesene :count števke|vnesenih :count števk',
    'pin_pad' => 'Tipkovnica PIN',
    'digit' => 'Števka :digit',
    'backspace' => 'Vračalka',
    'ok' => 'V redu',
    'ok_aria' => 'V redu — potrdi PIN',
    'sign_out' => 'Odjavi se',
    'forgot_pin' => 'Si pozabil PIN? Odjavi se — znova se lahko prijaviš z geslom svojega računa in nastaviš nov PIN. Podatki se ne izgubijo.',

    'errors' => [
        'pin_length' => 'PIN mora imeti vsaj 6 števke.',

        'too_many_attempts' => 'Preveč poskusov — poskusi znova čez :secondss.',
        // i18n-review: sl · errors.incorrect_pin_remaining — rewritten from a count label to real dual
        // agreement, so the verb now moves with the noun across all four arms. The
        // grammar is checked against the rule table; the word order and the "še" want
        // a native eye.
        'incorrect_pin_remaining' => 'Napačen PIN. Preostal je še :count poskus.|Napačen PIN. Preostala sta še :count poskusa.|Napačen PIN. Preostali so še :count poskusi.|Napačen PIN. Preostalo je še :count poskusov.',
        'incorrect_pin' => 'Napačen PIN.',
    ],
];
