<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Ta različica Beatraxa nima kam shraniti odklepnega ključa, zato biometrično odklepanje ni na voljo. Omejitev ni tvoja naprava.',
    'error_enroll_unprotected' => 'Biometrično odklepanje potrebuje shrambo ključev operacijskega sistema, ta namestitev pa je nima. Vpis bi pustil odklepni ključ berljiv ob tvojih podatkih, zato tu ni na voljo.',
    'error_enroll_locked' => 'Pred vpisom odkleni aplikacijo.',
    'error_enroll_failed' => 'Tvoja naprava je zavrnila shranjevanje ključa. Biometrično odklepanje ni na voljo.',
    'heading' => 'Zaklepanje aplikacije',

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
    'biometric_unavailable' => 'Ta različica Beatraxa ne more ponuditi biometričnega odklepanja. Tu je edino odklepanje tvoj PIN.',

    'deenroll_modal_heading' => 'Odstrani biometrično odklepanje — potrdi s PIN-om',
    'current_pin_label' => 'Trenutni PIN',
    'remove_biometric' => 'Odstrani biometrijo',
    'keep_biometric' => 'Obdrži biometrijo',

    'auto_lock' => 'Samodejno zakleni po',
    // i18n-review: sl · auto_lock_note — ":window" is core::durations.seconds and
    // reads "30 sekund"; "v 30 sekundah" needs a locative the shared key cannot
    // supply, so the window became the predicate "ta zamik pa je največ".
    // Whether "zamik" is the word a Slovenian reader expects is the open half.
    'auto_lock_note' => 'Beatrax se zaklene po tem času brez dejavnosti — in prej, če ga zapustiš: preklop v drugo aplikacijo ali skrivanje oziroma zapiranje okna zaklene Beatrax ne glede na to nastavitev, ta zamik pa je največ :window.',
    'idle_1' => '1 minuti',
    'idle_5' => '5 minutah',
    'idle_15' => '15 minutah',
    'idle_30' => '30 minutah',

    'disable_modal_heading' => 'Izklopi zaklepanje aplikacije — potrdi s PIN-om',
    'disable_lock' => 'Izklopi zaklepanje',
    'keep_lock' => 'Obdrži zaklepanje',

    'forgot_modal_heading' => 'Ponastavi PIN — potrdi z geslom računa',
    'forgot_modal_body' => 'Geslo tvojega računa obnovi ključ zaklepanja, zato ponastavitev PIN-a ne izgubi podatkov — dokler to geslo še odpira zaklep. Geslo, ponastavljeno s kodo za obnovitev ali nastavljeno zate od lastnika računa, ga ne odpre več.',
    'confirm_new_pin_label' => 'Potrdi nov PIN',
    'reset_pin' => 'Ponastavi PIN',
    'cancel' => 'Prekliči',

    'change_modal_heading' => 'Spremeni PIN — potrdi s trenutnim PIN-om',
    'keep_pin' => 'Obdrži PIN',

    'error_pin_too_short' => 'PIN mora imeti vsaj 6 števke.',
    'error_pin_digits' => 'PIN mora imeti :min do :max števk — samo številke.',
    'error_pin_mismatch' => 'PIN-a se ne ujemata. Poskusi znova.',
    'error_pin_required' => 'Vnesi svoj PIN.',
    'error_pin_incorrect' => 'Napačen PIN.',
    'error_account_password_required' => 'Vnesi geslo svojega računa.',
    'error_account_password' => 'Napačno geslo računa.',
    'change_pin_success' => 'Tvoj šifrirni ključ je znova zavarovan z novim PIN-om.',
    'error_forgot_failed' => 'Ponastavitev PIN-a ni uspela — ključ za obnovitev ni na voljo.',
    'error_enable_first' => 'Pred vpisom biometrije vklopi zaklepanje s PIN-om.',
    'error_disable_blocked_by_encryption' => 'Tvoji zapiski in podatki o nasprotnih strankah so šifrirani s ključem, ki ga hrani ta zaklep aplikacije, zato bi jih izklop pustil neberljive. Zaklep ostane vklopljen — raje zamenjaj PIN.',
    'error_key_material_lost' => 'Ta naprava ne hrani več ključa, ki odpira tvoje šifrirane podatke, zato jih nov PIN ne bo znova naredil berljivih. Obnovi šifrirano varnostno kopijo, narejeno, ko je ključ še deloval — s seznanitvijo se ta naprava ne more vrniti nazaj, ker seznanjanje potrebuje prav tisti zaklep aplikacije, ki ga ta ključ odpira.',
    'error_recovery_wrap_stale' => 'Geslo računa tega zaklepanja aplikacije ne odpre več — spremenjeno je bilo po tem, ko je bilo zaklepanje nastavljeno. PIN še vedno deluje, a za njim ne ostane nič, če ga pozabiš. Znova poveži geslo računa zdaj.',
    'relink_recovery' => 'Znova poveži geslo računa',
    'relink_modal_heading' => 'Znova poveži geslo računa — potrdi s PIN-om',
    'relink_recovery_success' => 'Geslo računa lahko to zaklepanje aplikacije spet obnovi.',
];
