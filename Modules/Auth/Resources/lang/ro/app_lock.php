<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Deblocarea biometrică nu este disponibilă pe acest dispozitiv.',
    'error_enroll_locked' => 'Deblochează aplicația înainte de înrolare.',
    'error_enroll_failed' => 'Dispozitivul tău a refuzat să stocheze cheia. Deblocarea biometrică nu este disponibilă.',
    'heading' => 'Blocarea aplicației',

    'moved_help' => 'Codul PIN, temporizarea blocării automate și deblocarea biometrică se află în setările de sincronizare ale acestui dispozitiv.',
    'moved_cta' => 'Deschide Sincronizare și dispozitiv',

    'toggle_label' => 'Blochează aplicația cu cod PIN',
    'toggle_description' => 'Înlocuiește autentificarea zilnică cu un cod PIN. Sesiunile rămân active 30 de zile.',

    'setup_heading' => 'Setează un cod PIN ca să activezi blocarea',
    'new_pin_label' => 'Cod PIN nou (6–10 cifre)',
    'confirm_pin_label' => 'Confirmă codul PIN',
    'account_password_label' => 'Parola contului',
    'account_password_note' => '(necesară pentru a crea o cheie de recuperare)',
    'account_password_placeholder' => 'Parola contului tău',
    'set_pin' => 'Setează codul PIN',

    'pin_row_label' => 'Cod PIN',
    'pin_row_description' => 'Schimbă-ți codul PIN actual.',
    'change_pin' => 'Schimbă codul PIN',
    'forgot_pin_link' => 'Ți-ai uitat codul PIN? Resetează-l cu parola contului.',

    'biometric_enrolled_description' => 'Acest dispozitiv este înrolat pentru deblocare biometrică.',
    'biometric_enroll_description' => 'Înrolează acest dispozitiv pentru deblocare biometrică.',
    'remove' => 'Elimină',
    'enroll' => 'Înrolează',
    'biometric_unavailable' => 'Deblocarea biometrică nu este disponibilă pe acest dispozitiv.',

    'deenroll_modal_heading' => 'Elimină deblocarea biometrică — confirmă cu codul PIN',
    'current_pin_label' => 'Codul PIN actual',
    'remove_biometric' => 'Elimină biometria',
    'keep_biometric' => 'Păstrează biometria',

    'auto_lock' => 'Blochează automat după',
    'idle_1' => '1 minut',
    'idle_5' => '5 minute',
    'idle_15' => '15 minute',
    'idle_30' => '30 de minute',

    'disable_modal_heading' => 'Dezactivează blocarea aplicației — confirmă cu codul PIN',
    'disable_lock' => 'Dezactivează blocarea',
    'keep_lock' => 'Păstrează blocarea aplicației',

    'forgot_modal_heading' => 'Resetează codul PIN — confirmă cu parola contului',
    'forgot_modal_body' => 'Parola contului recuperează cheia de blocare, așa că resetarea codului PIN nu duce niciodată la pierderea datelor.',
    'confirm_new_pin_label' => 'Confirmă noul cod PIN',
    'reset_pin' => 'Resetează codul PIN',
    'cancel' => 'Anulează',

    'change_modal_heading' => 'Schimbă codul PIN — confirmă cu codul PIN actual',
    'keep_pin' => 'Păstrează codul PIN',

    'error_pin_too_short' => 'Codul PIN trebuie să aibă cel puțin 6 cifre.',
    'error_pin_mismatch' => 'Codurile PIN nu coincid. Încearcă din nou.',
    'error_pin_incorrect' => 'Cod PIN incorect.',
    'error_account_password' => 'Parola contului este incorectă.',
    'change_pin_success' => 'Cheia ta de criptare a fost resecurizată cu noul cod PIN.',
    'error_forgot_failed' => 'Resetarea codului PIN a eșuat — cheia de recuperare nu este disponibilă.',
    'error_enable_first' => 'Activează mai întâi blocarea cu cod PIN, apoi înrolează biometria.',
];
