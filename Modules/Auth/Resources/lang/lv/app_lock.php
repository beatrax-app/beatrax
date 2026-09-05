<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Šai Beatrax versijai nav kur glabāt atbloķēšanas atslēgu, tāpēc biometriskā atbloķēšana netiek piedāvāta. Ierobežojums nav jūsu ierīce.',
    'error_enroll_unprotected' => 'Biometriskajai atbloķēšanai nepieciešama operētājsistēmas atslēgu glabātuve, un šai instalācijai tādas nav. Reģistrēšana atstātu atbloķēšanas atslēgu lasāmu blakus taviem datiem, tāpēc tā šeit netiek piedāvāta.',
    'error_enroll_locked' => 'Pirms reģistrēšanas atbloķējiet lietotni.',
    'error_enroll_failed' => 'Ierīce atteicās saglabāt atslēgu. Biometriskā atbloķēšana nav pieejama.',
    'heading' => 'Lietotnes bloķēšana',

    'toggle_label' => 'Bloķēt lietotni ar PIN kodu',
    'toggle_description' => 'Aizstāj ikdienas pieteikšanos ar PIN kodu. Sesijas paliek aktīvas 30 dienas.',

    'setup_heading' => 'Iestatiet PIN kodu, lai ieslēgtu bloķēšanu',
    'new_pin_label' => 'Jauns PIN kods (6–10 cipari)',
    'confirm_pin_label' => 'Apstipriniet PIN kodu',
    'account_password_label' => 'Konta parole',
    'account_password_note' => '(nepieciešama, lai izveidotu atkopšanas atslēgu)',
    'account_password_placeholder' => 'Jūsu konta parole',
    'set_pin' => 'Iestatīt PIN kodu',

    'pin_row_label' => 'PIN kods',
    'pin_row_description' => 'Nomainiet pašreizējo PIN kodu.',
    'change_pin' => 'Mainīt PIN kodu',
    'forgot_pin_link' => 'Aizmirsāt PIN kodu? Atiestatiet to ar konta paroli.',

    'biometric_enrolled_description' => 'Šī ierīce ir reģistrēta biometriskajai atbloķēšanai.',
    'biometric_enroll_description' => 'Reģistrējiet šo ierīci, lai atbloķētu ar biometriju.',
    'remove' => 'Noņemt',
    'enroll' => 'Reģistrēt',
    'biometric_unavailable' => 'Šī Beatrax versija nevar piedāvāt biometrisko atbloķēšanu. Šeit vienīgā atbloķēšana ir jūsu PIN kods.',

    'deenroll_modal_heading' => 'Noņemt biometrisko atbloķēšanu — apstipriniet ar PIN kodu',
    'current_pin_label' => 'Pašreizējais PIN kods',
    'remove_biometric' => 'Noņemt biometriju',
    'keep_biometric' => 'Paturēt biometriju',

    'auto_lock' => 'Automātiski bloķēt pēc',
    // i18n-review: lv · auto_lock_note — ":window" is core::durations.seconds and
    // reads "30 sekundes"; "30 sekunžu laikā" needs a genitive the shared key
    // cannot supply, so the window became "tas aizņem ne vairāk kā". Whether
    // that or a "laikā" rewrite reads better wants a native eye.
    'auto_lock_note' => 'Beatrax bloķējas pēc šāda dīkstāves laika — un ātrāk, ja to pamet: pārslēgšanās uz citu lietotni vai loga paslēpšana vai aizvēršana bloķē Beatrax neatkarīgi no šī iestatījuma, un tas aizņem ne vairāk kā :window.',
    'idle_1' => '1 minūte',
    'idle_5' => '5 minūtes',
    'idle_15' => '15 minūtes',
    'idle_30' => '30 minūtes',

    'disable_modal_heading' => 'Izslēgt lietotnes bloķēšanu — apstipriniet ar PIN kodu',
    'disable_lock' => 'Izslēgt bloķēšanu',
    'keep_lock' => 'Paturēt lietotnes bloķēšanu',

    'forgot_modal_heading' => 'Atiestatīt PIN kodu — apstipriniet ar konta paroli',
    'forgot_modal_body' => 'Konta parole atgūst bloķēšanas atslēgu, tāpēc PIN koda atiestatīšana nekad nezaudē datus.',
    'confirm_new_pin_label' => 'Apstipriniet jauno PIN kodu',
    'reset_pin' => 'Atiestatīt PIN kodu',
    'cancel' => 'Atcelt',

    'change_modal_heading' => 'Mainīt PIN kodu — apstipriniet ar pašreizējo PIN kodu',
    'keep_pin' => 'Paturēt PIN kodu',

    'error_pin_too_short' => 'PIN kodā jābūt vismaz 6 cipariem.',
    'error_pin_digits' => 'PIN kodā jābūt :min līdz :max cipariem — tikai cipari.',
    'error_pin_mismatch' => 'PIN kodi nesakrīt. Mēģiniet vēlreiz.',
    'error_pin_required' => 'Ievadiet savu PIN kodu.',
    'error_pin_incorrect' => 'Nepareizs PIN kods.',
    'error_account_password_required' => 'Ievadiet sava konta paroli.',
    'error_account_password' => 'Nepareiza konta parole.',
    'change_pin_success' => 'Jūsu šifrēšanas atslēga ir no jauna aizsargāta ar jauno PIN kodu.',
    'error_forgot_failed' => 'PIN koda atiestatīšana neizdevās — atkopšanas atslēga nav pieejama.',
    'error_enable_first' => 'Vispirms ieslēdziet PIN koda bloķēšanu un tikai tad reģistrējiet biometriju.',
    'error_disable_blocked_by_encryption' => 'Tavas piezīmes un darījuma partneru dati ir šifrēti ar atslēgu, ko glabā šī lietotnes bloķēšana, tāpēc tās izslēgšana padarītu tos nelasāmus. Bloķēšana paliek ieslēgta — labāk nomaini savu PIN.',
    'error_key_material_lost' => 'Šī ierīce vairs neglabā atslēgu, kas atver tavus šifrētos datus, tāpēc jauns PIN tos atkal lasāmus nepadarīs. Savieno šo ierīci ar tādu, kurai atslēga vēl ir, lai tos atgūtu.',
    'error_recovery_wrap_stale' => 'Tava konta parole vairs neatver šo lietotnes bloķēšanu — tā tika nomainīta pēc bloķēšanas iestatīšanas. PIN kods vēl darbojas, bet aiz tā nekas nepaliek, ja to aizmirsti. Piesaisti konta paroli no jauna tagad.',
    'relink_recovery' => 'Piesaistīt konta paroli no jauna',
    'relink_modal_heading' => 'Piesaistīt konta paroli no jauna — apstiprini ar PIN kodu',
    'relink_recovery_success' => 'Tava konta parole atkal var atjaunot šo lietotnes bloķēšanu.',
];
