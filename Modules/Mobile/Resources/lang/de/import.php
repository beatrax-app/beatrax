<?php

declare(strict_types=1);

return [
    'page_title' => 'Von einem anderen Gerät importieren',

    'heading' => 'Von einem anderen Gerät importieren',
    'subtitle' => 'Richte dieses Telefon mit eigenem Konto und eigener Sperre ein und koppele es dann mit deinem anderen Gerät, um deinen Verlauf zu übernehmen.',

    'username' => 'Benutzername',
    'password' => 'Passwort',
    'password_help' => 'Mindestens 12 Zeichen — es gibt kein Zurücksetzen des Passworts, nur Wiederherstellungscodes.',
    'confirm_password' => 'Passwort bestätigen',

    'requirements_aria' => 'Passwortanforderungen',
    'req_length' => 'Mindestens 12 Zeichen',
    'req_match' => 'Beide Passwörter stimmen überein',
    'req_met' => '(erfüllt)',
    'req_unmet' => '(noch nicht erfüllt)',

    'pin' => 'PIN der App-Sperre',
    'pin_help' => '6-10 Ziffern — entsperrt dieses Gerät.',
    'confirm_pin' => 'PIN bestätigen',
    'continue' => 'Weiter',

    'failed_heading' => 'Einrichtung nicht abgeschlossen',
    'failed_body' => 'Dein Konto wurde erstellt, aber die Einrichtung dieses Geräts konnte nicht abgeschlossen werden. Du kannst es gefahrlos noch einmal versuchen.',
    'try_again' => 'Noch mal versuchen',

    'recovery_heading' => 'Diese Wiederherstellungscodes sichern',
    'recovery_body' => 'Drucke sie aus oder bewahre sie an einem sicheren Ort auf. Sie werden nicht noch einmal angezeigt.',
    'already_heading' => 'Dieses Gerät ist bereits eingerichtet',
    'already_body' => 'Dein Konto gibt es auf diesem Gerät schon. Fahre mit dem Koppeln fort, um es mit deinen anderen Geräten zu verbinden.',
    'recovery_download' => 'Als .txt herunterladen',
    'recovery_copy' => 'Codes kopieren',
    'recovery_copied' => 'Kopiert',
    'recovery_copy_failed' => 'Kopieren nicht möglich. Notieren Sie die Codes stattdessen.',
    'recovery_saved' => 'In deinen Downloads gespeichert.',
    'recovery_share_title' => 'Beatrax-Wiederherstellungscodes',
    'recovery_share_message' => 'Bewahren Sie diese sicher auf.',
    'recovery_save_failed' => 'Die Datei konnte nicht gespeichert werden. Notieren Sie die Codes stattdessen.',
    'recovery_confirm' => 'Ich habe diese Codes an einem sicheren Ort gespeichert.',
    'continue_to_pairing' => 'Weiter zum Koppeln',

    'errors' => [
        'username_required' => 'Benutzername ist erforderlich.',
        'passwords_mismatch' => 'Die Passwörter stimmen nicht überein.',
        'password_length' => 'Verwende mindestens 12 Zeichen.',
        'pin_length' => 'Die PIN muss mindestens 6 Ziffern haben.',
        'pin_digits' => 'Die PIN muss 6 bis 10 Ziffern haben — nur Zahlen.',
        'pins_mismatch' => 'Die PINs stimmen nicht überein. Versuch es noch mal.',
        'session_expired' => 'Deine Sitzung ist abgelaufen, bevor die Einrichtung fertig war. Gib deine PIN und dein Passwort erneut ein.',
        'retry_failed' => 'Die Einrichtung dieses Geräts konnte immer noch nicht abgeschlossen werden. Versuch es noch mal.',
        'account_failed' => 'Das Konto konnte nicht erstellt werden.',
    ],
];
