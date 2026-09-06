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
    'forgot_pin' => "PIN oublié ? Déconnecte-toi : si le mot de passe de ton compte ouvre encore ce verrou, tu peux te reconnecter, définir un nouveau PIN et ne rien perdre. Un mot de passe réinitialisé avec un code de récupération, ou défini pour toi par le propriétaire du compte, ne l'ouvre plus.",

    'errors' => [
        'pin_length' => 'Le PIN doit comporter au moins 6 chiffres.',

        'too_many_attempts' => 'Trop de tentatives — réessaie dans :secondss.',
        'incorrect_pin_remaining' => 'PIN incorrect. :count tentative restante.|PIN incorrect. :count tentatives restantes.',
        'incorrect_pin' => 'PIN incorrect.',
    ],
];
