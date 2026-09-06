<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Ova verzija Beatraxa nema gde da sačuva ključ za otključavanje, pa se biometrijsko otključavanje ne nudi. Ograničenje nije tvoj uređaj.',
    'error_enroll_unprotected' => 'Biometrijsko otključavanje zahteva skladište ključeva operativnog sistema, a ova instalacija ga nema. Upis bi ostavio ključ za otključavanje čitljiv pored tvojih podataka, pa se ovde ne nudi.',
    'error_enroll_locked' => 'Otključaj aplikaciju pre upisa.',
    'error_enroll_failed' => 'Tvoj uređaj je odbio da sačuva ključ. Biometrijsko otključavanje nije dostupno.',
    'heading' => 'Zaključavanje aplikacije',

    'toggle_label' => 'Zaključaj aplikaciju PIN-om',
    'toggle_description' => 'Zamenjuje svakodnevnu prijavu PIN-om. Sesije ostaju aktivne 30 dana.',

    'setup_heading' => 'Postavi PIN da uključiš zaključavanje',
    'new_pin_label' => 'Novi PIN (6–10 cifara)',
    'confirm_pin_label' => 'Potvrdi PIN',
    'account_password_label' => 'Lozinka naloga',
    'account_password_note' => '(potrebna za pravljenje ključa za oporavak)',
    'account_password_placeholder' => 'Lozinka tvog naloga',
    'set_pin' => 'Postavi PIN',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Promeni svoj trenutni PIN.',
    'change_pin' => 'Promeni PIN',
    'forgot_pin_link' => 'Zaboravio si PIN? Resetuj ga lozinkom svog naloga.',

    'biometric_enrolled_description' => 'Ovaj uređaj je upisan za biometrijsko otključavanje.',
    'biometric_enroll_description' => 'Upiši ovaj uređaj za otključavanje biometrijom.',
    'remove' => 'Ukloni',
    'enroll' => 'Upiši',
    'biometric_unavailable' => 'Ova verzija Beatraxa ne može da ponudi biometrijsko otključavanje. Ovde je jedino otključavanje tvoj PIN.',

    'deenroll_modal_heading' => 'Ukloni biometrijsko otključavanje — potvrdi PIN-om',
    'current_pin_label' => 'Trenutni PIN',
    'remove_biometric' => 'Ukloni biometriju',
    'keep_biometric' => 'Zadrži biometriju',

    'auto_lock' => 'Automatski zaključaj posle',
    'auto_lock_note' => 'Beatrax se zaključava posle toliko vremena bez aktivnosti — i pre toga ako ga napustiš: prelazak u drugu aplikaciju ili skrivanje ili zatvaranje prozora zaključava Beatrax u roku od :window, bez obzira na ovo podešavanje.',
    'idle_1' => '1 minut',
    'idle_5' => '5 minuta',
    'idle_15' => '15 minuta',
    'idle_30' => '30 minuta',

    'disable_modal_heading' => 'Isključi zaključavanje aplikacije — potvrdi PIN-om',
    'disable_lock' => 'Isključi zaključavanje',
    'keep_lock' => 'Zadrži zaključavanje',

    'forgot_modal_heading' => 'Resetuj PIN — potvrdi lozinkom naloga',
    'forgot_modal_body' => 'Lozinka tvog naloga vraća ključ zaključavanja, pa resetovanje PIN-a ne gubi podatke — dok god ta lozinka i dalje otvara zaključavanje. Lozinka resetovana kodom za oporavak ili ona koju ti je postavio vlasnik naloga više ga ne otvara.',
    'confirm_new_pin_label' => 'Potvrdi novi PIN',
    'reset_pin' => 'Resetuj PIN',
    'cancel' => 'Otkaži',

    'change_modal_heading' => 'Promeni PIN — potvrdi trenutnim PIN-om',
    'keep_pin' => 'Zadrži PIN',

    'error_pin_too_short' => 'PIN mora da ima bar 6 cifre.',
    'error_pin_digits' => 'PIN mora da ima :min do :max cifara — samo brojevi.',
    'error_pin_mismatch' => 'PIN-ovi se ne poklapaju. Probaj ponovo.',
    'error_pin_required' => 'Unesi svoj PIN.',
    'error_pin_incorrect' => 'Neispravan PIN.',
    'error_account_password_required' => 'Unesi lozinku svog naloga.',
    'error_account_password' => 'Neispravna lozinka naloga.',
    'change_pin_success' => 'Tvoj ključ za šifrovanje je ponovo obezbeđen novim PIN-om.',
    'error_forgot_failed' => 'Resetovanje PIN-a nije uspelo — ključ za oporavak nije dostupan.',
    'error_enable_first' => 'Pre upisa biometrije uključi zaključavanje PIN-om.',
    'error_disable_blocked_by_encryption' => 'Tvoje beleške i podaci o drugim stranama šifrovani su ključem koji čuva ovo zaključavanje aplikacije, pa bi njegovo isključivanje ostavilo te podatke nečitljivima. Zaključavanje ostaje uključeno — umesto toga promeni PIN.',
    'error_key_material_lost' => 'Ovaj uređaj više ne čuva ključ koji otvara tvoje šifrovane podatke, pa ih nov PIN neće ponovo učiniti čitljivima. Vrati šifrovanu rezervnu kopiju napravljenu dok je ključ još radio — uparivanjem se ovaj uređaj ne može vratiti, jer uparivanje traži upravo ono zaključavanje aplikacije koje taj ključ otvara.',
    'error_recovery_wrap_stale' => 'Lozinka naloga više ne otvara ovo zaključavanje aplikacije — promenjena je nakon što je zaključavanje podešeno. PIN i dalje radi, ali iza njega ne ostaje ništa ako ga zaboraviš. Ponovo poveži lozinku naloga sada.',
    'relink_recovery' => 'Ponovo poveži lozinku naloga',
    'relink_modal_heading' => 'Ponovo poveži lozinku naloga — potvrdi PIN-om',
    'relink_recovery_success' => 'Lozinka naloga ponovo može da vrati ovo zaključavanje aplikacije.',
];
