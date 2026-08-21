<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Biometrično odklepanje na tej napravi ni na voljo.',
    'error_enroll_unprotected' => 'Biometrično odklepanje potrebuje shrambo ključev operacijskega sistema, ta namestitev pa je nima. Vpis bi pustil odklepni ključ berljiv ob tvojih podatkih, zato tu ni na voljo.',
    'error_enroll_locked' => 'Pred vpisom odkleni aplikacijo.',
    'error_enroll_failed' => 'Tvoja naprava je zavrnila shranjevanje ključa. Biometrično odklepanje ni na voljo.',
    'heading' => 'Zaklepanje aplikacije',

    'moved_help' => 'Tvoj PIN, čas samodejnega zaklepanja in biometrično odklepanje so pri nastavitvah sinhronizacije te naprave.',
    'moved_cta' => 'Odpri Sinhronizacijo in napravo',

    'toggle_label' => 'Zakleni aplikacijo s PIN-om',
    'toggle_description' => 'Vsakodnevno prijavo zamenja PIN. Seje ostanejo aktivne 30 dni.',

    'setup_heading' => 'Nastavi PIN, da vklopiš zaklepanje',
    'new_pin_label' => 'Nov PIN (6–10 števk)',
    'confirm_pin_label' => 'Potrdi PIN',
    'account_password_label' => 'Geslo računa',
    'account_password_note' => '(potrebno za ustvarjanje ključa za obnovitev)',
    'account_password_placeholder' => 'Geslo tvojega računa',
    'set_pin' => 'Nastavi PIN',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Spremeni svoj trenutni PIN.',
    'change_pin' => 'Spremeni PIN',
    'forgot_pin_link' => 'Si pozabil PIN? Ponastavi ga z geslom svojega računa.',

    'biometric_enrolled_description' => 'Ta naprava je vpisana za biometrično odklepanje.',
    'biometric_enroll_description' => 'Vpiši to napravo za odklepanje z biometrijo.',
    'remove' => 'Odstrani',
    'enroll' => 'Vpiši',
    'biometric_unavailable' => 'Biometrično odklepanje na tej napravi ni na voljo.',

    'deenroll_modal_heading' => 'Odstrani biometrično odklepanje — potrdi s PIN-om',
    'current_pin_label' => 'Trenutni PIN',
    'remove_biometric' => 'Odstrani biometrijo',
    'keep_biometric' => 'Obdrži biometrijo',

    'auto_lock' => 'Samodejno zakleni po',
    'idle_1' => '1 minuti',
    'idle_5' => '5 minutah',
    'idle_15' => '15 minutah',
    'idle_30' => '30 minutah',

    'disable_modal_heading' => 'Izklopi zaklepanje aplikacije — potrdi s PIN-om',
    'disable_lock' => 'Izklopi zaklepanje',
    'keep_lock' => 'Obdrži zaklepanje',

    'forgot_modal_heading' => 'Ponastavi PIN — potrdi z geslom računa',
    'forgot_modal_body' => 'Geslo tvojega računa obnovi ključ zaklepanja, zato ponastavitev PIN-a nikoli ne izgubi podatkov.',
    'confirm_new_pin_label' => 'Potrdi nov PIN',
    'reset_pin' => 'Ponastavi PIN',
    'cancel' => 'Prekliči',

    'change_modal_heading' => 'Spremeni PIN — potrdi s trenutnim PIN-om',
    'keep_pin' => 'Obdrži PIN',

    'error_pin_too_short' => 'PIN mora imeti vsaj 6 števke.',
    'error_pin_mismatch' => 'PIN-a se ne ujemata. Poskusi znova.',
    'error_pin_incorrect' => 'Napačen PIN.',
    'error_account_password' => 'Napačno geslo računa.',
    'change_pin_success' => 'Tvoj šifrirni ključ je znova zavarovan z novim PIN-om.',
    'error_forgot_failed' => 'Ponastavitev PIN-a ni uspela — ključ za obnovitev ni na voljo.',
    'error_enable_first' => 'Pred vpisom biometrije vklopi zaklepanje s PIN-om.',
];
