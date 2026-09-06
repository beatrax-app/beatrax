<?php

declare(strict_types=1);

return [
    'page_title' => 'Sblocca',

    'digits_entered' => ':count cifra inserita|:count cifre inserite',
    'pin_pad' => 'Tastierino del PIN',
    'digit' => 'Cifra :digit',
    'backspace' => 'Tasto indietro',
    'ok' => 'OK',
    'ok_aria' => 'OK — conferma il PIN',
    'sign_out' => 'Esci',
    'forgot_pin' => "Hai dimenticato il PIN? Esci: se la password del tuo account apre ancora questo blocco puoi rientrare, impostare un nuovo PIN e non perdere nulla. Una password reimpostata con un codice di recupero, o impostata per te dal titolare dell'account, non lo apre più.",

    'errors' => [
        'pin_length' => 'Il PIN deve avere almeno 6 cifre.',

        'too_many_attempts' => 'Troppi tentativi — riprova tra :secondss.',
        'incorrect_pin_remaining' => 'PIN errato. Resta :count tentativo.|PIN errato. Restano :count tentativi.',
        'incorrect_pin' => 'PIN errato.',
    ],
];
