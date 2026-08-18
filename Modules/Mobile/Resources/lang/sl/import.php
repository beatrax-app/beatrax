<?php

declare(strict_types=1);

return [
    'page_title' => 'Uvoz iz druge naprave',

    'heading' => 'Uvoz iz druge naprave',
    'subtitle' => 'Nastavi ta telefon z lastnim računom in zaklepom, nato ga seznani s svojo drugo napravo, da preneseš zgodovino.',

    'username' => 'Uporabniško ime',
    'password' => 'Geslo',
    'password_help' => 'Vsaj 12 znakov — ponastavitev gesla ni mogoča, na voljo so le kode za obnovitev.',
    'confirm_password' => 'Potrdi geslo',
    'pin' => 'PIN za zaklep aplikacije',
    'pin_help' => '6-10 števk — odklene to napravo.',
    'confirm_pin' => 'Potrdi PIN',
    'continue' => 'Nadaljuj',

    'failed_heading' => 'Nastavitev ni bila dokončana',
    'failed_body' => 'Tvoj račun je ustvarjen, vendar nastavitve te naprave ni bilo mogoče dokončati. Varno lahko poskusiš znova.',
    'try_again' => 'Poskusi znova',

    'recovery_heading' => 'Shrani te kode za obnovitev',
    'recovery_body' => 'Natisni jih ali shrani na varno mesto. Znova ne bodo prikazane.',
    'already_heading' => 'Ta naprava je že nastavljena',
    'already_body' => 'Tvoj račun v tej napravi že obstaja. Nadaljuj na seznanjanje in jo poveži s svojimi drugimi napravami.',
    'recovery_download' => 'Prenesi kot .txt',
    'recovery_copy' => 'Kopiraj kode',
    'recovery_copied' => 'Kopirano',
    'recovery_saved' => 'Shranjeno v mapo Prenosi.',
    'recovery_confirm' => 'Te kode so shranjene na varnem mestu.',
    'continue_to_pairing' => 'Nadaljuj na seznanjanje',

    'errors' => [
        'passwords_mismatch' => 'Gesli se ne ujemata.',
        'password_length' => 'Uporabi vsaj 12 znakov.',
        'pin_length' => 'PIN mora imeti vsaj 6 števke.',
        'pins_mismatch' => 'PIN-a se ne ujemata. Poskusi znova.',
        'session_expired' => 'Tvoja seja je potekla, preden se je nastavitev dokončala. Znova vnesi PIN in geslo.',
        'retry_failed' => 'Nastavitve te naprave še vedno ni bilo mogoče dokončati. Poskusi znova.',
        'account_failed' => 'Računa ni bilo mogoče ustvariti.',
    ],
];
