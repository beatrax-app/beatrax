<?php

declare(strict_types=1);

return [
    'page_title' => 'Déverrouiller',

    'digits_entered' => ':count chiffre saisi|:count chiffres saisis',
    'pin_pad' => 'Pavé PIN',
    'digit' => 'Chiffre :digit',
    'backspace' => 'Retour arrière',
    'ok' => 'OK',
    'ok_aria' => 'OK — confirmer le PIN',
    'sign_out' => 'Se déconnecter',
    'forgot_pin' => 'PIN oublié ? Déconnecte-toi',

    'errors' => [
        'pin_length' => 'Le PIN doit comporter au moins 6 chiffres.',

        'too_many_attempts' => 'Trop de tentatives — réessaie dans :secondss.',
        'incorrect_pin_remaining' => 'PIN incorrect. :count tentative restante.|PIN incorrect. :count tentatives restantes.',
        'incorrect_pin' => 'PIN incorrect.',

        'pin_changed' => 'Le PIN de cet appareil a été modifié pendant le déverrouillage. Saisis le PIN actuel.',

        'biometric_reset' => 'Le déverrouillage biométrique a été réinitialisé. Saisis ton PIN, puis réactive-le dans Verrouillage de l\'app.',
    ],
];
