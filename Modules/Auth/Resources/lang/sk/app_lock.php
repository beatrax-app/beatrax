<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Biometrické odomknutie nie je na tomto zariadení dostupné.',
    'error_enroll_locked' => 'Pred registráciou odomkni aplikáciu.',
    'error_enroll_failed' => 'Tvoje zariadenie odmietlo uložiť kľúč. Biometrické odomknutie nie je dostupné.',
    'heading' => 'Zámok aplikácie',

    'moved_help' => 'PIN, čas automatického zamknutia aj biometrické odomknutie nájdeš v nastaveniach synchronizácie tohto zariadenia.',
    'moved_cta' => 'Otvoriť Synchronizáciu a zariadenie',

    'toggle_label' => 'Zamykať aplikáciu PIN-om',
    'toggle_description' => 'Nahradí každodenné prihlasovanie PIN-om. Relácie zostanú aktívne 30 dní.',

    'setup_heading' => 'Nastav PIN a zapni zámok',
    'new_pin_label' => 'Nový PIN (4–10 číslic)',
    'confirm_pin_label' => 'Potvrď PIN',
    'account_password_label' => 'Heslo k účtu',
    'account_password_note' => '(potrebné na vytvorenie obnovovacieho kľúča)',
    'account_password_placeholder' => 'Tvoje heslo k účtu',
    'set_pin' => 'Nastaviť PIN',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Zmeň svoj súčasný PIN.',
    'change_pin' => 'Zmeniť PIN',
    'forgot_pin_link' => 'Zabudol si PIN? Obnov ho heslom k účtu.',

    'biometric_enrolled_description' => 'Toto zariadenie je zaregistrované na biometrické odomknutie.',
    'biometric_enroll_description' => 'Zaregistruj toto zariadenie na odomykanie biometriou.',
    'remove' => 'Odstrániť',
    'enroll' => 'Zaregistrovať',
    'biometric_unavailable' => 'Biometrické odomknutie nie je na tomto zariadení dostupné.',

    'deenroll_modal_heading' => 'Odstrániť biometrické odomknutie — potvrď PIN-om',
    'current_pin_label' => 'Súčasný PIN',
    'remove_biometric' => 'Odstrániť biometriu',
    'keep_biometric' => 'Ponechať biometriu',

    'auto_lock' => 'Automaticky zamknúť po',
    'idle_1' => '1 minúte',
    'idle_5' => '5 minútach',
    'idle_15' => '15 minútach',
    'idle_30' => '30 minútach',

    'disable_modal_heading' => 'Vypnúť zámok aplikácie — potvrď PIN-om',
    'disable_lock' => 'Vypnúť zámok',
    'keep_lock' => 'Ponechať zámok aplikácie',

    'forgot_modal_heading' => 'Obnoviť PIN — potvrď heslom k účtu',
    'forgot_modal_body' => 'Heslo k účtu obnoví kľúč zámku, takže obnovením PIN-u nikdy neprídeš o údaje.',
    'confirm_new_pin_label' => 'Potvrď nový PIN',
    'reset_pin' => 'Obnoviť PIN',
    'cancel' => 'Zrušiť',

    'change_modal_heading' => 'Zmeniť PIN — potvrď súčasným PIN-om',
    'keep_pin' => 'Ponechať PIN',

    'error_pin_too_short' => 'PIN musí mať aspoň 4 číslice.',
    'error_pin_mismatch' => 'PIN-y sa nezhodujú. Skús to znova.',
    'error_pin_incorrect' => 'Nesprávny PIN.',
    'error_account_password' => 'Nesprávne heslo k účtu.',
    'change_pin_success' => 'Tvoj šifrovací kľúč je znova zabezpečený novým PIN-om.',
    'error_forgot_failed' => 'Obnovenie PIN-u zlyhalo — obnovovací kľúč nie je dostupný.',
    'error_enable_first' => 'Najprv zapni zámok PIN-om, až potom registruj biometriu.',
];
