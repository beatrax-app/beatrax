<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Tato verze Beatraxu nemá kam uložit odemykací klíč, takže se biometrické odemykání nenabízí. Omezením není tvoje zařízení.',
    'error_enroll_unprotected' => 'Biometrické odemykání potřebuje úložiště klíčů operačního systému a tato instalace žádné nemá. Registrace by nechala odemykací klíč čitelný vedle tvých dat, takže se tu nenabízí.',
    'error_enroll_locked' => 'Před registrací odemkni aplikaci.',
    'error_enroll_failed' => 'Zařízení odmítlo uložit klíč. Biometrické odemykání není dostupné.',
    'heading' => 'Zámek aplikace',

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
    'biometric_unavailable' => 'Tato verze Beatraxu neumí biometrické odemykání. Jediné odemknutí je tu tvůj PIN.',

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
    'error_pin_digits' => 'PIN musí mít :min až :max číslic — pouze číslice.',
    'error_pin_mismatch' => 'PINy se neshodují. Zkus to znovu.',
    'error_pin_required' => 'Zadej svůj PIN.',
    'error_pin_incorrect' => 'Nesprávný PIN.',
    'error_account_password_required' => 'Zadej své heslo k účtu.',
    'error_account_password' => 'Nesprávné heslo k účtu.',
    'change_pin_success' => 'Tvůj šifrovací klíč je znovu zabezpečený novým PINem.',
    'error_forgot_failed' => 'Reset PINu se nezdařil — obnovovací klíč není dostupný.',
    'error_enable_first' => 'Než zaregistruješ biometriku, zapni nejdřív zámek PINem.',
    'error_disable_blocked_by_encryption' => 'Tvoje poznámky a údaje o protistranách jsou šifrované klíčem, který drží tento zámek aplikace, takže jeho vypnutí by je nechalo nečitelné. Zámek zůstává zapnutý — místo toho si změň PIN.',
    'error_key_material_lost' => 'Toto zařízení už nedrží klíč, který otevírá tvoje šifrovaná data, takže nový PIN je znovu čitelnými neudělá. Spáruj toto zařízení s jiným, které klíč stále má, a obnov je.',
    'error_recovery_wrap_stale' => 'Heslo k účtu už tento zámek aplikace neotevře — bylo změněno až po jeho nastavení. Tvůj PIN stále funguje, ale pokud ho zapomeneš, nezůstane za ním nic. Propoj heslo k účtu znovu.',
    'relink_recovery' => 'Znovu propojit heslo k účtu',
    'relink_modal_heading' => 'Znovu propojit heslo k účtu — potvrď PINem',
    'relink_recovery_success' => 'Heslo k účtu může tento zámek aplikace opět obnovit.',
];
