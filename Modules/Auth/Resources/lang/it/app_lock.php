<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Lo sblocco biometrico non è disponibile su questo dispositivo.',
    'error_enroll_unprotected' => 'Lo sblocco biometrico richiede un archivio chiavi del sistema operativo, e questa installazione non ne ha uno. La registrazione lascerebbe la chiave di sblocco leggibile accanto ai tuoi dati, quindi qui non viene offerta.',
    'error_enroll_locked' => "Sblocca l'app prima di registrare il dispositivo.",
    'error_enroll_failed' => 'Il tuo dispositivo ha rifiutato di salvare la chiave. Lo sblocco biometrico non è disponibile.',
    'heading' => 'Blocco app',

    'moved_help' => 'Il PIN, i tempi di blocco automatico e lo sblocco biometrico si trovano nelle impostazioni di sincronizzazione di questo dispositivo.',
    'moved_cta' => 'Apri Sincronizzazione e dispositivo',

    'toggle_label' => "Blocca l'app con un PIN",
    'toggle_description' => "Sostituisce l'accesso quotidiano con un PIN. Le sessioni restano attive per 30 giorni.",

    'setup_heading' => 'Imposta un PIN per attivare il blocco',
    'new_pin_label' => 'Nuovo PIN (6–10 cifre)',
    'confirm_pin_label' => 'Conferma il PIN',
    'account_password_label' => "Password dell'account",
    'account_password_note' => '(necessaria per creare una chiave di recupero)',
    'account_password_placeholder' => 'La password del tuo account',
    'set_pin' => 'Imposta il PIN',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Cambia il tuo PIN attuale.',
    'change_pin' => 'Cambia PIN',
    'forgot_pin_link' => 'Hai dimenticato il PIN? Reimpostalo con la password del tuo account.',

    'biometric_enrolled_description' => 'Questo dispositivo è registrato per lo sblocco biometrico.',
    'biometric_enroll_description' => 'Registra questo dispositivo per sbloccarlo con la biometria.',
    'remove' => 'Rimuovi',
    'enroll' => 'Registra',
    'biometric_unavailable' => 'Lo sblocco biometrico non è disponibile su questo dispositivo.',

    'deenroll_modal_heading' => 'Rimuovi lo sblocco biometrico — conferma con il PIN',
    'current_pin_label' => 'PIN attuale',
    'remove_biometric' => 'Rimuovi la biometria',
    'keep_biometric' => 'Mantieni la biometria',

    'auto_lock' => 'Blocco automatico dopo',
    'idle_1' => '1 minuto',
    'idle_5' => '5 minuti',
    'idle_15' => '15 minuti',
    'idle_30' => '30 minuti',

    'disable_modal_heading' => 'Disattiva il blocco app — conferma con il PIN',
    'disable_lock' => 'Disattiva il blocco',
    'keep_lock' => 'Mantieni il blocco app',

    'forgot_modal_heading' => "Reimposta il PIN — conferma con la password dell'account",
    'forgot_modal_body' => 'La password del tuo account recupera la chiave di blocco, quindi reimpostare il PIN non fa mai perdere dati.',
    'confirm_new_pin_label' => 'Conferma il nuovo PIN',
    'reset_pin' => 'Reimposta il PIN',
    'cancel' => 'Annulla',

    'change_modal_heading' => 'Cambia il PIN — conferma con il PIN attuale',
    'keep_pin' => 'Mantieni il PIN',

    'error_pin_too_short' => 'Il PIN deve avere almeno 6 cifre.',
    'error_pin_mismatch' => 'I PIN non coincidono. Riprova.',
    'error_pin_required' => 'Inserisci il tuo PIN.',
    'error_pin_incorrect' => 'PIN errato.',
    'error_account_password_required' => 'Inserisci la password del tuo account.',
    'error_account_password' => "Password dell'account errata.",
    'change_pin_success' => 'La tua chiave di crittografia è stata protetta di nuovo con il tuo nuovo PIN.',
    'error_forgot_failed' => 'Reimpostazione del PIN non riuscita — la chiave di recupero non è disponibile.',
    'error_enable_first' => 'Attiva prima il blocco con PIN, poi registra la biometria.',
    'error_disable_blocked_by_encryption' => 'Le tue note e i dati delle controparti sono cifrati con la chiave che questo blocco dell\'app custodisce, quindi disattivarlo li renderebbe illeggibili. Il blocco resta attivo — cambia invece il tuo PIN.',
    'error_key_material_lost' => 'Questo dispositivo non custodisce più la chiave che apre i tuoi dati cifrati, quindi un nuovo PIN non li renderà di nuovo leggibili. Associa questo dispositivo a uno che ha ancora la chiave per recuperarli.',
    'error_recovery_wrap_stale' => 'La password dell\'account non apre più questo blocco app — è stata cambiata dopo che il blocco era già attivo. Il tuo PIN funziona ancora, ma dietro non resta nulla se lo dimentichi. Ricollega ora la password dell\'account.',
    'relink_recovery' => 'Ricollega la password dell\'account',
    'relink_modal_heading' => 'Ricollega la password dell\'account — conferma con il PIN',
    'relink_recovery_success' => 'La password dell\'account può di nuovo recuperare questo blocco app.',
];
