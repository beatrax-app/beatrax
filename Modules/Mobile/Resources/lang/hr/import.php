<?php

declare(strict_types=1);

return [
    'page_title' => 'Uvoz s drugog uređaja',

    'heading' => 'Uvoz s drugog uređaja',
    'subtitle' => 'Postavi ovaj telefon s vlastitim računom i zaključavanjem, a zatim ga upari s drugim uređajem za preuzimanje svoje povijesti.',

    'username' => 'Korisničko ime',
    'password' => 'Lozinka',
    'password_help' => 'Najmanje 12 znakova — lozinku nije moguće poništiti, postoje samo kodovi za oporavak.',
    'confirm_password' => 'Potvrdi lozinku',

    'requirements_aria' => 'Zahtjevi za lozinku',
    'req_length' => 'Najmanje 12 znakova',
    'req_match' => 'Obje se lozinke podudaraju',
    'req_met' => '(ispunjeno)',
    'req_unmet' => '(još nije ispunjeno)',

    'pin' => 'PIN za zaključavanje aplikacije',
    'pin_help' => '6-10 znamenki — otključava ovaj uređaj.',
    'confirm_pin' => 'Potvrdi PIN',
    'continue' => 'Nastavi',

    'failed_heading' => 'Postavljanje nije dovršeno',
    'failed_body' => 'Tvoj je račun stvoren, ali postavljanje ovog uređaja nije bilo moguće dovršiti. Možeš sigurno pokušati ponovno.',
    'try_again' => 'Pokušaj ponovno',

    'recovery_heading' => 'Spremi ove kodove za oporavak',
    'recovery_body' => 'Ispiši ih ili spremi na sigurno mjesto. Neće se više prikazati.',
    'already_heading' => 'Ovaj je uređaj već postavljen',
    'already_body' => 'Tvoj račun postoji na ovom uređaju. Nastavi na uparivanje radi povezivanja s ostalim uređajima.',
    'recovery_download' => 'Preuzmi kao .txt',
    'recovery_copy' => 'Kopiraj kodove',
    'recovery_copied' => 'Kopirano',
    'recovery_copy_failed' => 'Kopiranje nije uspjelo. Zapišite kodove.',
    'recovery_saved' => 'Spremljeno u mapu Preuzimanja.',
    'recovery_share_title' => 'Beatrax kodovi za oporavak',
    'recovery_share_message' => 'Čuvajte ih na sigurnom mjestu.',
    'recovery_save_failed' => 'Datoteku nije bilo moguće spremiti. Zapišite kodove.',
    'recovery_confirm' => 'Ovi su kodovi spremljeni na sigurno mjesto.',
    'continue_to_pairing' => 'Nastavi na uparivanje',

    'errors' => [
        'username_required' => 'Korisničko ime je obavezno.',
        'passwords_mismatch' => 'Lozinke se ne podudaraju.',
        'password_length' => 'Upotrijebi najmanje 12 znakova.',
        'pin_length' => 'PIN mora imati najmanje 6 znamenke.',
        'pin_digits' => 'PIN mora imati 6 do 10 znamenki — samo brojevi.',
        'pins_mismatch' => 'PIN-ovi se ne podudaraju. Pokušaj ponovno.',
        'session_expired' => 'Tvoja je sesija istekla prije dovršetka postavljanja. Ponovno unesi PIN i lozinku.',
        'retry_failed' => 'Postavljanje ovog uređaja i dalje nije dovršeno. Pokušaj ponovno.',
        'account_failed' => 'Račun nije bilo moguće stvoriti.',
    ],
];
