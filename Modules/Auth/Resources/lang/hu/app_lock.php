<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'A Beatrax e verziójának nincs hová eltárolnia a feloldókulcsot, ezért a biometrikus feloldás nem érhető el. Nem az eszközöd a korlát.',
    'error_enroll_unprotected' => 'A biometrikus feloldáshoz az operációs rendszer kulcstárolója kell, ennek a telepítésnek pedig nincs ilyen. A regisztráció olvashatóan hagyná a feloldókulcsot az adataid mellett, ezért itt nem érhető el.',
    'error_enroll_locked' => 'Oldd fel az alkalmazást a regisztrálás előtt.',
    'error_enroll_failed' => 'Az eszközöd elutasította a kulcs tárolását. A biometrikus feloldás nem érhető el.',
    'heading' => 'Alkalmazászár',

    'toggle_label' => 'Alkalmazás zárolása PIN-kóddal',
    'toggle_description' => 'A napi bejelentkezést PIN-kódra cseréli. A munkamenetek 30 napig aktívak maradnak.',

    'setup_heading' => 'Állíts be PIN-kódot a zárolás bekapcsolásához',
    'new_pin_label' => 'Új PIN-kód (6–10 számjegy)',
    'confirm_pin_label' => 'PIN-kód megerősítése',
    'account_password_label' => 'Fiókjelszó',
    'account_password_note' => '(a helyreállítási kulcs létrehozásához szükséges)',
    'account_password_placeholder' => 'A fiókod jelszava',
    'set_pin' => 'PIN-kód beállítása',

    'pin_row_label' => 'PIN-kód',
    'pin_row_description' => 'Módosítsd a jelenlegi PIN-kódodat.',
    'change_pin' => 'PIN-kód módosítása',
    'forgot_pin_link' => 'Elfelejtetted a PIN-kódot? Állítsd vissza a fiókjelszavaddal.',

    'biometric_enrolled_description' => 'Ez az eszköz regisztrálva van a biometrikus feloldáshoz.',
    'biometric_enroll_description' => 'Regisztráld ezt az eszközt a biometrikus feloldáshoz.',
    'remove' => 'Eltávolítás',
    'enroll' => 'Regisztrálás',
    'biometric_unavailable' => 'A Beatrax e verziója nem tud biometrikus feloldást nyújtani. Itt a PIN-kódod az egyetlen feloldás.',

    'deenroll_modal_heading' => 'Biometrikus feloldás eltávolítása — erősítsd meg PIN-kóddal',
    'current_pin_label' => 'Jelenlegi PIN-kód',
    'remove_biometric' => 'Biometria eltávolítása',
    'keep_biometric' => 'Biometria megtartása',

    'auto_lock' => 'Automatikus zárolás ennyi után',
    'idle_1' => '1 perc',
    'idle_5' => '5 perc',
    'idle_15' => '15 perc',
    'idle_30' => '30 perc',

    'disable_modal_heading' => 'Alkalmazászár kikapcsolása — erősítsd meg PIN-kóddal',
    'disable_lock' => 'Zárolás kikapcsolása',
    'keep_lock' => 'Alkalmazászár megtartása',

    'forgot_modal_heading' => 'PIN-kód visszaállítása — erősítsd meg a fiókjelszavaddal',
    'forgot_modal_body' => 'A fiókjelszavad visszaállítja a zárolási kulcsot, így a PIN-kód visszaállításakor soha nem vész el adat.',
    'confirm_new_pin_label' => 'Új PIN-kód megerősítése',
    'reset_pin' => 'PIN-kód visszaállítása',
    'cancel' => 'Mégse',

    'change_modal_heading' => 'PIN-kód módosítása — erősítsd meg a jelenlegi PIN-kóddal',
    'keep_pin' => 'PIN-kód megtartása',

    'error_pin_too_short' => 'A PIN-kódnak legalább 6 számjegyűnek kell lennie.',
    'error_pin_digits' => 'A PIN-kódnak :min–:max számjegyből kell állnia — csak számok.',
    'error_pin_mismatch' => 'A PIN-kódok nem egyeznek. Próbáld újra.',
    'error_pin_required' => 'Add meg a PIN-kódodat.',
    'error_pin_incorrect' => 'Hibás PIN-kód.',
    'error_account_password_required' => 'Add meg a fiókod jelszavát.',
    'error_account_password' => 'Hibás fiókjelszó.',
    'change_pin_success' => 'A titkosítási kulcsodat az új PIN-kód védi mostantól.',
    'error_forgot_failed' => 'A PIN-kód visszaállítása sikertelen — a helyreállítási kulcs nem érhető el.',
    'error_enable_first' => 'Előbb kapcsold be a PIN-zárat, mielőtt biometriát regisztrálsz.',
    'error_disable_blocked_by_encryption' => 'A jegyzeteid és a partnereid adatai azzal a kulccsal vannak titkosítva, amelyet ez az alkalmazászár őriz, így a zár kikapcsolása olvashatatlanná tenné őket. A zár bekapcsolva marad — inkább a PIN-kódodat változtasd meg.',
    'error_key_material_lost' => 'Ez az eszköz már nem őrzi a titkosított adataidat nyitó kulcsot, ezért egy új PIN-kód sem teszi őket újra olvashatóvá. Párosítsd ezt az eszközt olyannal, amelyen a kulcs még megvan, hogy visszakapd őket.',
    'error_recovery_wrap_stale' => 'A fiókjelszavad már nem nyitja ezt az alkalmazászárat — a zár beállítása után változott meg. A PIN-kódod még működik, de ha elfelejted, nincs mögötte semmi. Kösd össze újra a fiókjelszavadat.',
    'relink_recovery' => 'Fiókjelszó újbóli összekötése',
    'relink_modal_heading' => 'Fiókjelszó újbóli összekötése — erősítsd meg PIN-kóddal',
    'relink_recovery_success' => 'A fiókjelszavad újra vissza tudja állítani ezt az alkalmazászárat.',
];
