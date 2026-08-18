<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Biometrisch ontgrendelen is niet beschikbaar op dit apparaat.',
    'error_enroll_locked' => 'Ontgrendel de app voordat je dit instelt.',
    'error_enroll_failed' => 'Je apparaat wilde de sleutel niet opslaan. Biometrisch ontgrendelen is niet beschikbaar.',
    'heading' => 'App-vergrendeling',

    // Instellingen verwijst alleen door; de bediening zelf staat op het
    // synchronisatiescherm op /sync#app-lock.
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
    'error_pin_mismatch' => 'Pincodes komen niet overeen. Probeer het opnieuw.',
    'error_pin_incorrect' => 'Onjuiste pincode.',
    'error_account_password' => 'Onjuist accountwachtwoord.',
    'change_pin_success' => 'Je encryptiesleutel is opnieuw beveiligd met je nieuwe pincode.',
    'error_forgot_failed' => 'Pincode opnieuw instellen mislukt — de herstelsleutel is niet beschikbaar.',
    'error_enable_first' => 'Schakel eerst de pincodevergrendeling in voordat je biometrie registreert.',
];
