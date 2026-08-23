<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Biomeetriline avamine pole selles seadmes saadaval.',
    'error_enroll_unprotected' => 'Biomeetriline avamine vajab operatsioonisüsteemi võtmehoidlat ja sellel paigaldusel seda pole. Registreerimine jätaks avamisvõtme sinu andmete kõrvale loetavaks, seega seda siin ei pakuta.',
    'error_enroll_locked' => 'Ava rakendus enne registreerimist.',
    'error_enroll_failed' => 'Seade keeldus võtit salvestamast. Biomeetriline avamine pole saadaval.',
    'heading' => 'Rakenduse lukk',

    'moved_help' => 'Sinu PIN-kood, automaatse lukustuse aeg ja biomeetriline avamine asuvad selle seadme sünkroonimisseadetes.',
    'moved_cta' => 'Ava sünkroonimine ja seade',

    'toggle_label' => 'Lukusta rakendus PIN-koodiga',
    'toggle_description' => 'Asendab igapäevase sisselogimise PIN-koodiga. Sessioonid püsivad aktiivsena 30 päeva.',

    'setup_heading' => 'Määra PIN-kood, et lukk sisse lülitada',
    'new_pin_label' => 'Uus PIN-kood (6–10 numbrit)',
    'confirm_pin_label' => 'Kinnita PIN-kood',
    'account_password_label' => 'Konto parool',
    'account_password_note' => '(vajalik taastevõtme loomiseks)',
    'account_password_placeholder' => 'Sinu konto parool',
    'set_pin' => 'Määra PIN-kood',

    'pin_row_label' => 'PIN-kood',
    'pin_row_description' => 'Muuda praegust PIN-koodi.',
    'change_pin' => 'Muuda PIN-koodi',
    'forgot_pin_link' => 'Unustasid PIN-koodi? Lähtesta see konto parooliga.',

    'biometric_enrolled_description' => 'See seade on biomeetriliseks avamiseks registreeritud.',
    'biometric_enroll_description' => 'Registreeri see seade biomeetriliseks avamiseks.',
    'remove' => 'Eemalda',
    'enroll' => 'Registreeri',
    'biometric_unavailable' => 'Biomeetriline avamine pole selles seadmes saadaval.',

    'deenroll_modal_heading' => 'Eemalda biomeetriline avamine — kinnita PIN-koodiga',
    'current_pin_label' => 'Praegune PIN-kood',
    'remove_biometric' => 'Eemalda biomeetria',
    'keep_biometric' => 'Jäta biomeetria alles',

    'auto_lock' => 'Automaatne lukustus pärast',
    'idle_1' => '1 minut',
    'idle_5' => '5 minutit',
    'idle_15' => '15 minutit',
    'idle_30' => '30 minutit',

    'disable_modal_heading' => 'Lülita rakenduse lukk välja — kinnita PIN-koodiga',
    'disable_lock' => 'Lülita lukk välja',
    'keep_lock' => 'Jäta lukk alles',

    'forgot_modal_heading' => 'Lähtesta PIN-kood — kinnita konto parooliga',
    'forgot_modal_body' => 'Sinu konto parool taastab luku võtme, seega PIN-koodi lähtestamine ei kaota kunagi andmeid.',
    'confirm_new_pin_label' => 'Kinnita uus PIN-kood',
    'reset_pin' => 'Lähtesta PIN-kood',
    'cancel' => 'Tühista',

    'change_modal_heading' => 'Muuda PIN-koodi — kinnita praeguse PIN-koodiga',
    'keep_pin' => 'Jäta PIN-kood alles',

    'error_pin_too_short' => 'PIN-kood peab olema vähemalt 6 numbrit.',
    'error_pin_digits' => 'PIN-kood peab olema 6–10 numbrit — ainult numbrid.',
    'error_pin_mismatch' => 'PIN-koodid ei kattu. Proovi uuesti.',
    'error_pin_required' => 'Sisesta oma PIN-kood.',
    'error_pin_incorrect' => 'Vale PIN-kood.',
    'error_account_password_required' => 'Sisesta oma konto parool.',
    'error_account_password' => 'Vale konto parool.',
    'change_pin_success' => 'Sinu krüpteerimisvõti on uue PIN-koodiga uuesti kaitstud.',
    'error_forgot_failed' => 'PIN-koodi lähtestamine ebaõnnestus — taastevõti pole saadaval.',
    'error_enable_first' => 'Enne biomeetria registreerimist lülita PIN-lukk sisse.',
    'error_disable_blocked_by_encryption' => 'Sinu märkmed ja vastaspoolte andmed on krüpteeritud võtmega, mida see rakenduse lukk hoiab, seega luku väljalülitamine muudaks need loetamatuks. Lukk jääb sisse — muuda selle asemel oma PIN-koodi.',
    'error_key_material_lost' => 'See seade ei hoia enam võtit, mis su krüpteeritud andmed avab, seega uus PIN-kood ei tee neid uuesti loetavaks. Seo see seade seadmega, millel on võti veel alles, et need taastada.',
    'error_recovery_wrap_stale' => 'Sinu konto parool ei ava enam seda rakenduse lukku — see vahetati pärast luku seadistamist. PIN-kood töötab veel, aga selle taga pole enam midagi, kui selle unustad. Seo konto parool nüüd uuesti.',
    'relink_recovery' => 'Seo konto parool uuesti',
    'relink_modal_heading' => 'Seo konto parool uuesti — kinnita PIN-koodiga',
    'relink_recovery_success' => 'Sinu konto parool saab selle rakenduse luku jälle taastada.',
];
