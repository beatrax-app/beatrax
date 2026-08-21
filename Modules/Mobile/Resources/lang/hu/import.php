<?php

declare(strict_types=1);

return [
    'page_title' => 'Importálás másik eszközről',

    'heading' => 'Importálás másik eszközről',
    'subtitle' => 'Állítsd be ezt a telefont saját fiókkal és zárolással, majd párosítsd a másik eszközöddel, hogy áthozd az előzményeidet.',

    'username' => 'Felhasználónév',
    'password' => 'Jelszó',
    'password_help' => 'Legalább 12 karakter — nincs jelszó-visszaállítás, csak helyreállítási kódok.',
    'confirm_password' => 'Jelszó megerősítése',

    'requirements_aria' => 'Jelszókövetelmények',
    'req_length' => 'Legalább 12 karakter',
    'req_match' => 'A két jelszó egyezik',
    'req_met' => '(teljesül)',
    'req_unmet' => '(még nem teljesül)',

    'pin' => 'Alkalmazászár PIN-kódja',
    'pin_help' => '6-10 számjegy — ezzel oldható fel ez az eszköz.',
    'confirm_pin' => 'PIN-kód megerősítése',
    'continue' => 'Folytatás',

    'failed_heading' => 'A beállítás nem fejeződött be',
    'failed_body' => 'A fiókod létrejött, de az eszköz beállítását nem tudtuk befejezni. Nyugodtan próbáld újra.',
    'try_again' => 'Újrapróbálás',

    'recovery_heading' => 'Mentsd el ezeket a helyreállítási kódokat',
    'recovery_body' => 'Nyomtasd ki, vagy mentsd el őket biztonságos helyre. Többé nem jelennek meg.',
    'already_heading' => 'Ez az eszköz már be van állítva',
    'already_body' => 'A fiókod létezik ezen az eszközön. Folytasd a párosítással, hogy összekapcsold a többi eszközöddel.',
    'recovery_download' => 'Letöltés .txt fájlként',
    'recovery_copy' => 'Kódok másolása',
    'recovery_copied' => 'Másolva',
    'recovery_copy_failed' => 'A másolás nem sikerült. Írja le a kódokat.',
    'recovery_saved' => 'Elmentve a letöltéseid közé.',
    'recovery_share_title' => 'Beatrax helyreállítási kódok',
    'recovery_share_message' => 'Tartsa ezeket biztonságos helyen.',
    'recovery_save_failed' => 'A fájl mentése nem sikerült. Írja le a kódokat.',
    'recovery_confirm' => 'Biztonságos helyre mentettem ezeket a kódokat.',
    'continue_to_pairing' => 'Tovább a párosításhoz',

    'errors' => [
        'username_required' => 'A felhasználónév megadása kötelező.',
        'passwords_mismatch' => 'A jelszavak nem egyeznek.',
        'password_length' => 'Használj legalább 12 karaktert.',
        'pin_length' => 'A PIN-kódnak legalább 6 számjegyből kell állnia.',
        'pins_mismatch' => 'A PIN-kódok nem egyeznek. Próbáld újra.',
        'session_expired' => 'A munkameneted lejárt, mielőtt a beállítás befejeződött volna. Add meg újra a PIN-kódodat és a jelszavadat.',
        'retry_failed' => 'Az eszköz beállítását továbbra sem sikerült befejezni. Próbáld újra.',
        'account_failed' => 'A fiókot nem sikerült létrehozni.',
    ],
];
