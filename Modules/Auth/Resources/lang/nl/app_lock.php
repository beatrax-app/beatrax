<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Biometrisch ontgrendelen is niet beschikbaar op dit apparaat.',
    'error_enroll_unprotected' => 'Biometrisch ontgrendelen heeft een sleutelopslag van het besturingssysteem nodig, en deze installatie heeft die niet. Registreren zou de ontgrendelsleutel leesbaar naast je gegevens laten staan, dus dat wordt hier niet aangeboden.',
    'error_enroll_locked' => 'Ontgrendel de app voordat je dit instelt.',
    'error_enroll_failed' => 'Je apparaat wilde de sleutel niet opslaan. Biometrisch ontgrendelen is niet beschikbaar.',
    'heading' => 'App-vergrendeling',

    'moved_help' => 'Je pincode, automatische vergrendeling en biometrisch ontgrendelen staan bij de synchronisatie-instellingen van dit apparaat.',
    'moved_cta' => 'Synchronisatie & apparaat openen',

    'toggle_label' => 'App vergrendelen met pincode',
    'toggle_description' => 'Vervangt het dagelijkse inloggen door een pincode. Sessies blijven 30 dagen actief.',

    'setup_heading' => 'Stel een pincode in om vergrendeling in te schakelen',
    'new_pin_label' => 'Nieuwe pincode (6–10 cijfers)',
    'confirm_pin_label' => 'Pincode bevestigen',
    'account_password_label' => 'Accountwachtwoord',
    'account_password_note' => '(vereist om een herstelsleutel aan te maken)',
    'account_password_placeholder' => 'Je accountwachtwoord',
    'set_pin' => 'Pincode instellen',

    'pin_row_label' => 'Pincode',
    'pin_row_description' => 'Wijzig je huidige pincode.',
    'change_pin' => 'Pincode wijzigen',
    'forgot_pin_link' => 'Pincode vergeten? Stel deze opnieuw in met je accountwachtwoord.',

    'biometric_enrolled_description' => 'Dit apparaat is geregistreerd voor biometrisch ontgrendelen.',
    'biometric_enroll_description' => 'Registreer dit apparaat om te ontgrendelen met biometrie.',
    'remove' => 'Verwijderen',
    'enroll' => 'Registreren',
    'biometric_unavailable' => 'Biometrisch ontgrendelen is niet beschikbaar op dit apparaat.',

    'deenroll_modal_heading' => 'Biometrisch ontgrendelen verwijderen — bevestig met pincode',
    'current_pin_label' => 'Huidige pincode',
    'remove_biometric' => 'Biometrie verwijderen',
    'keep_biometric' => 'Biometrie behouden',

    'auto_lock' => 'Automatisch vergrendelen na',
    'idle_1' => '1 minuut',
    'idle_5' => '5 minuten',
    'idle_15' => '15 minuten',
    'idle_30' => '30 minuten',

    'disable_modal_heading' => 'App-vergrendeling uitschakelen — bevestig met pincode',
    'disable_lock' => 'Vergrendeling uitschakelen',
    'keep_lock' => 'App-vergrendeling behouden',

    'forgot_modal_heading' => 'Pincode opnieuw instellen — bevestig met accountwachtwoord',
    'forgot_modal_body' => 'Je accountwachtwoord herstelt de vergrendelingssleutel, zodat het opnieuw instellen van de pincode nooit gegevens verliest.',
    'confirm_new_pin_label' => 'Nieuwe pincode bevestigen',
    'reset_pin' => 'Pincode opnieuw instellen',
    'cancel' => 'Annuleren',

    'change_modal_heading' => 'Pincode wijzigen — bevestig met huidige pincode',
    'keep_pin' => 'Pincode behouden',

    'error_pin_too_short' => 'Pincode moet minstens 6 cijfers bevatten.',
    'error_pin_digits' => 'Pincode moet 6 tot 10 cijfers zijn — alleen cijfers.',
    'error_pin_mismatch' => 'Pincodes komen niet overeen. Probeer het opnieuw.',
    'error_pin_required' => 'Voer je pincode in.',
    'error_pin_incorrect' => 'Onjuiste pincode.',
    'error_account_password_required' => 'Voer je accountwachtwoord in.',
    'error_account_password' => 'Onjuist accountwachtwoord.',
    'change_pin_success' => 'Je encryptiesleutel is opnieuw beveiligd met je nieuwe pincode.',
    'error_forgot_failed' => 'Pincode opnieuw instellen mislukt — de herstelsleutel is niet beschikbaar.',
    'error_enable_first' => 'Schakel eerst de pincodevergrendeling in voordat je biometrie registreert.',
    'error_disable_blocked_by_encryption' => 'Je notities en tegenpartijgegevens zijn versleuteld met de sleutel die deze app-vergrendeling bewaart, dus de vergrendeling uitzetten zou ze onleesbaar maken. De vergrendeling blijft aan — wijzig in plaats daarvan je pincode.',
    'error_key_material_lost' => 'Dit apparaat heeft de sleutel die je versleutelde gegevens opent niet meer, dus een nieuwe pincode maakt ze niet weer leesbaar. Koppel dit apparaat aan een apparaat dat de sleutel nog wel heeft om ze terug te krijgen.',
    'error_recovery_wrap_stale' => 'Je accountwachtwoord opent deze app-vergrendeling niet meer — het is gewijzigd nadat de vergrendeling was ingesteld. Je pincode werkt nog, maar er zit niets meer achter als je die vergeet. Koppel je accountwachtwoord nu opnieuw.',
    'relink_recovery' => 'Accountwachtwoord opnieuw koppelen',
    'relink_modal_heading' => 'Accountwachtwoord opnieuw koppelen — bevestig met pincode',
    'relink_recovery_success' => 'Je accountwachtwoord kan deze app-vergrendeling weer herstellen.',
];
