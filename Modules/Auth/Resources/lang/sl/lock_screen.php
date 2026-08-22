<?php

declare(strict_types=1);

return [
    'native_unlock_reason' => 'Odkleni Beatrax',
    'native_unlock_failed' => 'Odklepanje ni uspelo. Namesto tega vnesi PIN.',
    'page_title' => 'Odklepanje · Beatrax',
    'sign_out' => 'Odjavi se',
    'forgot_pin' => 'Si pozabil PIN? Odjavi se — znova se lahko prijaviš z geslom svojega računa in nastaviš nov PIN. Podatki se ne izgubijo.',

    'digits_suffix' => 'vnesenih števk',
    'pad_label' => 'Tipkovnica PIN',
    'digit_aria' => 'Števka :digit',
    'backspace_aria' => 'Vračalka',
    'ok_aria' => 'V redu — potrdi PIN',
    'ok' => 'V redu',

    'error_too_short' => 'PIN mora imeti vsaj 6 števke.',

    'error_backoff' => 'Preveč poskusov — poskusi znova čez :wait.',

    // i18n-review: sl · error_incorrect_remaining — rewritten from a count label to real dual
    // agreement, so the verb now moves with the noun across all four arms. The
    // grammar is checked against the rule table; the word order and the "še" want
    // a native eye.
    'error_incorrect_remaining' => 'Napačen PIN. Preostal je še :count poskus.|Napačen PIN. Preostala sta še :count poskusa.|Napačen PIN. Preostali so še :count poskusi.|Napačen PIN. Preostalo je še :count poskusov.',
    'error_incorrect' => 'Napačen PIN.',
];
