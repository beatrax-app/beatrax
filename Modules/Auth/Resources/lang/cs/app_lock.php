<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Biometrické odemykání není na tomto zařízení dostupné.',
    'error_enroll_locked' => 'Před registrací odemkni aplikaci.',
    'error_enroll_failed' => 'Zařízení odmítlo uložit klíč. Biometrické odemykání není dostupné.',
    'heading' => 'Zámek aplikace',

    'moved_help' => 'PIN, čas automatického zamčení i biometrické odemykání najdeš v nastavení synchronizace tohoto zařízení.',
    'moved_cta' => 'Otevřít Synchronizaci a zařízení',

    'toggle_label' => 'Zamykat aplikaci PINem',
    'toggle_description' => 'Nahradí každodenní přihlašování PINem. Relace zůstávají aktivní 30 dní.',

    'setup_heading' => 'Nastav PIN a zapni zámek',
    'new_pin_label' => 'Nový PIN (6–10 číslic)',
    'confirm_pin_label' => 'Potvrď PIN',
    'account_password_label' => 'Heslo k účtu',
    'account_password_note' => '(potřebné k vytvoření obnovovacího klíče)',
    'account_password_placeholder' => 'Tvoje heslo k účtu',
    'set_pin' => 'Nastavit PIN',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Změň svůj současný PIN.',
    'change_pin' => 'Změnit PIN',
    'forgot_pin_link' => 'Nepamatuješ si PIN? Resetuj ho heslem k účtu.',

    'biometric_enrolled_description' => 'Toto zařízení je zaregistrované pro biometrické odemykání.',
    'biometric_enroll_description' => 'Zaregistruj toto zařízení, ať ho můžeš odemykat biometrikou.',
    'remove' => 'Odebrat',
    'enroll' => 'Zaregistrovat',
    'biometric_unavailable' => 'Biometrické odemykání není na tomto zařízení dostupné.',

    'deenroll_modal_heading' => 'Odebrat biometrické odemykání — potvrď PINem',
    'current_pin_label' => 'Současný PIN',
    'remove_biometric' => 'Odebrat biometriku',
    'keep_biometric' => 'Ponechat biometriku',

    'auto_lock' => 'Automaticky zamknout po',
    'idle_1' => '1 minutě',
    'idle_5' => '5 minutách',
    'idle_15' => '15 minutách',
    'idle_30' => '30 minutách',

    'disable_modal_heading' => 'Vypnout zámek aplikace — potvrď PINem',
    'disable_lock' => 'Vypnout zámek',
    'keep_lock' => 'Ponechat zámek aplikace',

    'forgot_modal_heading' => 'Resetovat PIN — potvrď heslem k účtu',
    'forgot_modal_body' => 'Heslo k účtu obnoví klíč zámku, takže při resetu PINu nikdy nepřijdeš o data.',
    'confirm_new_pin_label' => 'Potvrď nový PIN',
    'reset_pin' => 'Resetovat PIN',
    'cancel' => 'Zrušit',

    'change_modal_heading' => 'Změnit PIN — potvrď současným PINem',
    'keep_pin' => 'Ponechat PIN',

    'error_pin_too_short' => 'PIN musí mít aspoň 6 číslice.',
    'error_pin_mismatch' => 'PINy se neshodují. Zkus to znovu.',
    'error_pin_incorrect' => 'Nesprávný PIN.',
    'error_account_password' => 'Nesprávné heslo k účtu.',
    'change_pin_success' => 'Tvůj šifrovací klíč je znovu zabezpečený novým PINem.',
    'error_forgot_failed' => 'Reset PINu se nezdařil — obnovovací klíč není dostupný.',
    'error_enable_first' => 'Než zaregistruješ biometriku, zapni nejdřív zámek PINem.',
];
