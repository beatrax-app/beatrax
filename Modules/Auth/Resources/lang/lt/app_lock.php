<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Biometrinis atrakinimas šiame įrenginyje negalimas.',
    'error_enroll_locked' => 'Prieš registruodamas atrakink programėlę.',
    'error_enroll_failed' => 'Tavo įrenginys atsisakė išsaugoti raktą. Biometrinis atrakinimas negalimas.',
    'heading' => 'Programėlės užraktas',

    // Nustatymuose lieka tik nuoroda; patys valdikliai yra
    // sinchronizavimo ekrane adresu /sync#app-lock.
    'moved_help' => 'PIN kodas, automatinio užrakinimo laikas ir biometrinis atrakinimas yra šio įrenginio sinchronizavimo nustatymuose.',
    'moved_cta' => 'Atidaryti sinchronizavimą ir įrenginį',

    'toggle_label' => 'Užrakinti programėlę PIN kodu',
    'toggle_description' => 'Kasdienį prisijungimą pakeičia PIN kodas. Seansai lieka aktyvūs 30 dienų.',

    'setup_heading' => 'Nustatyk PIN kodą, kad įjungtum užraktą',
    'new_pin_label' => 'Naujas PIN kodas (4–10 skaitmenų)',
    'confirm_pin_label' => 'Patvirtink PIN kodą',
    'account_password_label' => 'Paskyros slaptažodis',
    'account_password_note' => '(reikalingas atkūrimo raktui sukurti)',
    'account_password_placeholder' => 'Tavo paskyros slaptažodis',
    'set_pin' => 'Nustatyti PIN kodą',

    'pin_row_label' => 'PIN kodas',
    'pin_row_description' => 'Pakeisk dabartinį PIN kodą.',
    'change_pin' => 'Keisti PIN kodą',
    'forgot_pin_link' => 'Pamiršai PIN kodą? Nustatyk jį iš naujo su paskyros slaptažodžiu.',

    'biometric_enrolled_description' => 'Šis įrenginys užregistruotas biometriniam atrakinimui.',
    'biometric_enroll_description' => 'Užregistruok šį įrenginį, kad galėtum atrakinti biometriniais duomenimis.',
    'remove' => 'Pašalinti',
    'enroll' => 'Registruoti',
    'biometric_unavailable' => 'Biometrinis atrakinimas šiame įrenginyje negalimas.',

    'deenroll_modal_heading' => 'Pašalinti biometrinį atrakinimą — patvirtink PIN kodu',
    'current_pin_label' => 'Dabartinis PIN kodas',
    'remove_biometric' => 'Pašalinti biometriją',
    'keep_biometric' => 'Palikti biometriją',

    'auto_lock' => 'Automatiškai užrakinti po',
    'idle_1' => '1 minutės',
    'idle_5' => '5 minučių',
    'idle_15' => '15 minučių',
    'idle_30' => '30 minučių',

    'disable_modal_heading' => 'Išjungti programėlės užraktą — patvirtink PIN kodu',
    'disable_lock' => 'Išjungti užraktą',
    'keep_lock' => 'Palikti programėlės užraktą',

    'forgot_modal_heading' => 'Nustatyti PIN kodą iš naujo — patvirtink paskyros slaptažodžiu',
    'forgot_modal_body' => 'Paskyros slaptažodis atkuria užrakto raktą, todėl iš naujo nustatant PIN kodą duomenys niekada neprarandami.',
    'confirm_new_pin_label' => 'Patvirtink naują PIN kodą',
    'reset_pin' => 'Nustatyti PIN kodą iš naujo',
    'cancel' => 'Atšaukti',

    'change_modal_heading' => 'Keisti PIN kodą — patvirtink dabartiniu PIN kodu',
    'keep_pin' => 'Palikti PIN kodą',

    'error_pin_too_short' => 'PIN kodą turi sudaryti bent 4 skaitmenys.',
    'error_pin_mismatch' => 'PIN kodai nesutampa. Bandyk dar kartą.',
    'error_pin_incorrect' => 'Neteisingas PIN kodas.',
    'error_account_password' => 'Neteisingas paskyros slaptažodis.',
    'change_pin_success' => 'Tavo šifravimo raktas iš naujo apsaugotas nauju PIN kodu.',
    'error_forgot_failed' => 'Nepavyko iš naujo nustatyti PIN kodo — atkūrimo raktas nepasiekiamas.',
    'error_enable_first' => 'Prieš registruodamas biometriją, pirmiausia įjunk PIN kodo užraktą.',
];
