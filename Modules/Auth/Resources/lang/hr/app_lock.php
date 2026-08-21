<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Biometrijsko otključavanje nije dostupno na ovom uređaju.',
    'error_enroll_unprotected' => 'Biometrijsko otključavanje treba spremište ključeva operacijskog sustava, a ova instalacija ga nema. Upis bi ostavio ključ za otključavanje čitljiv uz tvoje podatke, pa se ovdje ne nudi.',
    'error_enroll_locked' => 'Otključaj aplikaciju prije upisa.',
    'error_enroll_failed' => 'Tvoj uređaj je odbio pohraniti ključ. Biometrijsko otključavanje nije dostupno.',
    'heading' => 'Zaključavanje aplikacije',

    'moved_help' => 'Tvoj PIN, vrijeme automatskog zaključavanja i biometrijsko otključavanje nalaze se uz postavke sinkronizacije ovog uređaja.',
    'moved_cta' => 'Otvori Sinkronizaciju i uređaj',

    'toggle_label' => 'Zaključaj aplikaciju PIN-om',
    'toggle_description' => 'Zamjenjuje svakodnevnu prijavu PIN-om. Sesije ostaju aktivne 30 dana.',

    'setup_heading' => 'Postavi PIN da uključiš zaključavanje',
    'new_pin_label' => 'Novi PIN (6–10 znamenki)',
    'confirm_pin_label' => 'Potvrdi PIN',
    'account_password_label' => 'Lozinka računa',
    'account_password_note' => '(potrebna za izradu ključa za oporavak)',
    'account_password_placeholder' => 'Lozinka tvojeg računa',
    'set_pin' => 'Postavi PIN',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Promijeni svoj trenutačni PIN.',
    'change_pin' => 'Promijeni PIN',
    'forgot_pin_link' => 'Zaboravio si PIN? Poništi ga lozinkom svojeg računa.',

    'biometric_enrolled_description' => 'Ovaj uređaj je upisan za biometrijsko otključavanje.',
    'biometric_enroll_description' => 'Upiši ovaj uređaj za otključavanje biometrijom.',
    'remove' => 'Ukloni',
    'enroll' => 'Upiši',
    'biometric_unavailable' => 'Biometrijsko otključavanje nije dostupno na ovom uređaju.',

    'deenroll_modal_heading' => 'Ukloni biometrijsko otključavanje — potvrdi PIN-om',
    'current_pin_label' => 'Trenutačni PIN',
    'remove_biometric' => 'Ukloni biometriju',
    'keep_biometric' => 'Zadrži biometriju',

    'auto_lock' => 'Automatski zaključaj nakon',
    'idle_1' => '1 minute',
    'idle_5' => '5 minuta',
    'idle_15' => '15 minuta',
    'idle_30' => '30 minuta',

    'disable_modal_heading' => 'Isključi zaključavanje aplikacije — potvrdi PIN-om',
    'disable_lock' => 'Isključi zaključavanje',
    'keep_lock' => 'Zadrži zaključavanje',

    'forgot_modal_heading' => 'Poništi PIN — potvrdi lozinkom računa',
    'forgot_modal_body' => 'Lozinka tvojeg računa vraća ključ zaključavanja, pa poništavanje PIN-a nikad ne gubi podatke.',
    'confirm_new_pin_label' => 'Potvrdi novi PIN',
    'reset_pin' => 'Poništi PIN',
    'cancel' => 'Odustani',

    'change_modal_heading' => 'Promijeni PIN — potvrdi trenutačnim PIN-om',
    'keep_pin' => 'Zadrži PIN',

    'error_pin_too_short' => 'PIN mora imati barem 6 znamenke.',
    'error_pin_mismatch' => 'PIN-ovi se ne podudaraju. Pokušaj ponovno.',
    'error_pin_incorrect' => 'Neispravan PIN.',
    'error_account_password' => 'Neispravna lozinka računa.',
    'change_pin_success' => 'Tvoj ključ za šifriranje ponovno je osiguran novim PIN-om.',
    'error_forgot_failed' => 'Poništavanje PIN-a nije uspjelo — ključ za oporavak nije dostupan.',
    'error_enable_first' => 'Prije upisa biometrije uključi zaključavanje PIN-om.',
];
