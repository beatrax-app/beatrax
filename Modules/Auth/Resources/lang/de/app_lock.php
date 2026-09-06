<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Diese Version von Beatrax kann keinen Entsperrschlüssel ablegen, deshalb wird biometrisches Entsperren nicht angeboten. Nicht dein Gerät ist die Einschränkung.',
    'error_enroll_unprotected' => 'Biometrisches Entsperren braucht einen Schlüsselspeicher des Betriebssystems, und diese Installation hat keinen. Eine Registrierung würde den Entsperrschlüssel lesbar neben deinen Daten liegen lassen, deshalb wird sie hier nicht angeboten.',
    'error_enroll_locked' => 'Entsperre die App, bevor du dieses Gerät registrierst.',
    'error_enroll_failed' => 'Dein Gerät hat das Speichern des Schlüssels abgelehnt. Biometrisches Entsperren ist nicht verfügbar.',
    'heading' => 'App-Sperre',

    'toggle_label' => 'App mit PIN sperren',
    'toggle_description' => 'Ersetzt die tägliche Anmeldung durch eine PIN. Sitzungen bleiben 30 Tage aktiv.',

    'setup_heading' => 'Lege eine PIN fest, um die Sperre zu aktivieren',
    'new_pin_label' => 'Neue PIN (6–10 Ziffern)',
    'confirm_pin_label' => 'PIN bestätigen',
    'account_password_label' => 'Kontopasswort',
    'account_password_note' => '(erforderlich, um einen Wiederherstellungsschlüssel zu erstellen)',
    'account_password_placeholder' => 'Dein Kontopasswort',
    'set_pin' => 'PIN festlegen',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Ändere deine aktuelle PIN.',
    'change_pin' => 'PIN ändern',
    'forgot_pin_link' => 'PIN vergessen? Setze sie mit deinem Kontopasswort zurück.',

    'biometric_enrolled_description' => 'Dieses Gerät ist für biometrisches Entsperren registriert.',
    'biometric_enroll_description' => 'Registriere dieses Gerät, um es biometrisch zu entsperren.',
    'remove' => 'Entfernen',
    'enroll' => 'Registrieren',
    'biometric_unavailable' => 'Diese Version von Beatrax kann kein biometrisches Entsperren anbieten. Deine PIN ist hier die einzige Entsperrung.',

    'deenroll_modal_heading' => 'Biometrisches Entsperren entfernen — mit PIN bestätigen',
    'current_pin_label' => 'Aktuelle PIN',
    'remove_biometric' => 'Biometrie entfernen',
    'keep_biometric' => 'Biometrie behalten',

    'auto_lock' => 'Automatisch sperren nach',
    'auto_lock_note' => 'Beatrax sperrt nach dieser Zeit ohne Aktivität — und früher, wenn du es verlässt: Zu einer anderen App wechseln oder das Fenster ausblenden oder schließen sperrt Beatrax innerhalb von :window, unabhängig von dieser Einstellung.',
    'idle_1' => '1 Minute',
    'idle_5' => '5 Minuten',
    'idle_15' => '15 Minuten',
    'idle_30' => '30 Minuten',

    'disable_modal_heading' => 'App-Sperre deaktivieren — mit PIN bestätigen',
    'disable_lock' => 'Sperre deaktivieren',
    'keep_lock' => 'App-Sperre behalten',

    'forgot_modal_heading' => 'PIN zurücksetzen — mit Kontopasswort bestätigen',
    'forgot_modal_body' => 'Dein Kontopasswort stellt den Sperrschlüssel wieder her, sodass beim Zurücksetzen der PIN keine Daten verloren gehen — solange dieses Passwort die Sperre noch öffnet. Ein Passwort, das mit einem Wiederherstellungscode zurückgesetzt oder dir vom Kontoinhaber gesetzt wurde, öffnet sie nicht mehr.',
    'confirm_new_pin_label' => 'Neue PIN bestätigen',
    'reset_pin' => 'PIN zurücksetzen',
    'cancel' => 'Abbrechen',

    'change_modal_heading' => 'PIN ändern — mit aktueller PIN bestätigen',
    'keep_pin' => 'PIN behalten',

    'error_pin_too_short' => 'Die PIN muss mindestens 6 Ziffern haben.',
    'error_pin_digits' => 'Die PIN muss :min bis :max Ziffern haben — nur Zahlen.',
    'error_pin_mismatch' => 'Die PINs stimmen nicht überein. Versuch es noch mal.',
    'error_pin_required' => 'Gib deine PIN ein.',
    'error_pin_incorrect' => 'Falsche PIN.',
    'error_account_password_required' => 'Gib dein Kontopasswort ein.',
    'error_account_password' => 'Falsches Kontopasswort.',
    'change_pin_success' => 'Dein Verschlüsselungsschlüssel wurde mit deiner neuen PIN neu gesichert.',
    'error_forgot_failed' => 'Zurücksetzen der PIN fehlgeschlagen — der Wiederherstellungsschlüssel ist nicht verfügbar.',
    'error_enable_first' => 'Aktiviere zuerst die PIN-Sperre, bevor du Biometrie registrierst.',
    'error_disable_blocked_by_encryption' => 'Deine Notizen und Zahlungspartner-Daten sind mit dem Schlüssel verschlüsselt, den diese App-Sperre hält — sie auszuschalten würde sie unlesbar machen. Die Sperre bleibt an; ändere stattdessen deine PIN.',
    'error_key_material_lost' => 'Dieses Gerät hält den Schlüssel zu deinen verschlüsselten Daten nicht mehr, deshalb macht eine neue PIN sie nicht wieder lesbar. Stelle ein verschlüsseltes Backup wieder her, das erstellt wurde, solange der Schlüssel noch funktionierte — durch Koppeln kommt dieses Gerät nicht zurück, denn das Koppeln braucht die App-Sperre, die dieser Schlüssel öffnet.',
    'error_recovery_wrap_stale' => 'Dein Kontopasswort öffnet diese App-Sperre nicht mehr — es wurde nach dem Einrichten der Sperre geändert. Deine PIN funktioniert noch, aber dahinter liegt nichts mehr, falls du sie vergisst. Verknüpfe dein Kontopasswort jetzt neu.',
    'relink_recovery' => 'Kontopasswort neu verknüpfen',
    'relink_modal_heading' => 'Kontopasswort neu verknüpfen — mit PIN bestätigen',
    'relink_recovery_success' => 'Dein Kontopasswort kann diese App-Sperre wieder wiederherstellen.',
];
