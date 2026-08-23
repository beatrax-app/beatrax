<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Biometrisk opplåsing er ikke tilgjengelig på denne enheten.',
    'error_enroll_unprotected' => 'Biometrisk opplåsing trenger et nøkkellager i operativsystemet, og denne installasjonen har ingen. Registrering ville latt opplåsingsnøkkelen ligge lesbar ved siden av dataene dine, så det tilbys ikke her.',
    'error_enroll_locked' => 'Lås opp appen før du registrerer enheten.',
    'error_enroll_failed' => 'Enheten din nektet å lagre nøkkelen. Biometrisk opplåsing er ikke tilgjengelig.',
    'heading' => 'Applås',

    'moved_help' => 'PIN-koden din, tidspunkt for automatisk låsing og biometrisk opplåsing ligger sammen med synkroniseringsinnstillingene for denne enheten.',
    'moved_cta' => 'Åpne Synkronisering og enhet',

    'toggle_label' => 'Lås appen med PIN-kode',
    'toggle_description' => 'Erstatter daglig innlogging med en PIN-kode. Øktene er aktive i 30 dager.',

    'setup_heading' => 'Angi en PIN-kode for å slå på låsen',
    'new_pin_label' => 'Ny PIN-kode (6–10 sifre)',
    'confirm_pin_label' => 'Bekreft PIN-kode',
    'account_password_label' => 'Kontopassord',
    'account_password_note' => '(kreves for å opprette en gjenopprettingsnøkkel)',
    'account_password_placeholder' => 'Kontopassordet ditt',
    'set_pin' => 'Angi PIN-kode',

    'pin_row_label' => 'PIN-kode',
    'pin_row_description' => 'Endre den nåværende PIN-koden din.',
    'change_pin' => 'Endre PIN-kode',
    'forgot_pin_link' => 'Har du glemt PIN-koden? Tilbakestill den med kontopassordet ditt.',

    'biometric_enrolled_description' => 'Denne enheten er registrert for biometrisk opplåsing.',
    'biometric_enroll_description' => 'Registrer denne enheten for å låse opp med biometri.',
    'remove' => 'Fjern',
    'enroll' => 'Registrer',
    'biometric_unavailable' => 'Biometrisk opplåsing er ikke tilgjengelig på denne enheten.',

    'deenroll_modal_heading' => 'Fjern biometrisk opplåsing — bekreft med PIN-kode',
    'current_pin_label' => 'Nåværende PIN-kode',
    'remove_biometric' => 'Fjern biometri',
    'keep_biometric' => 'Behold biometri',

    'auto_lock' => 'Lås automatisk etter',
    'idle_1' => '1 minutt',
    'idle_5' => '5 minutter',
    'idle_15' => '15 minutter',
    'idle_30' => '30 minutter',

    'disable_modal_heading' => 'Slå av applås — bekreft med PIN-kode',
    'disable_lock' => 'Slå av låsen',
    'keep_lock' => 'Behold applås',

    'forgot_modal_heading' => 'Tilbakestill PIN-kode — bekreft med kontopassord',
    'forgot_modal_body' => 'Kontopassordet ditt gjenoppretter låsenøkkelen, så du mister aldri data når du tilbakestiller PIN-koden.',
    'confirm_new_pin_label' => 'Bekreft ny PIN-kode',
    'reset_pin' => 'Tilbakestill PIN-kode',
    'cancel' => 'Avbryt',

    'change_modal_heading' => 'Endre PIN-kode — bekreft med nåværende PIN-kode',
    'keep_pin' => 'Behold PIN-kode',

    'error_pin_too_short' => 'PIN-koden må ha minst 6 sifre.',
    'error_pin_digits' => 'PIN-koden må ha 6 til 10 sifre — bare tall.',
    'error_pin_mismatch' => 'PIN-kodene er ikke like. Prøv igjen.',
    'error_pin_required' => 'Tast inn PIN-koden din.',
    'error_pin_incorrect' => 'Feil PIN-kode.',
    'error_account_password_required' => 'Skriv inn kontopassordet ditt.',
    'error_account_password' => 'Feil kontopassord.',
    'change_pin_success' => 'Krypteringsnøkkelen din er sikret på nytt med den nye PIN-koden din.',
    'error_forgot_failed' => 'Tilbakestilling av PIN-koden mislyktes — gjenopprettingsnøkkelen er ikke tilgjengelig.',
    'error_enable_first' => 'Slå på PIN-låsen før du registrerer biometri.',
    'error_disable_blocked_by_encryption' => 'Notatene dine og motpartsopplysningene er kryptert med nøkkelen denne app-låsen holder, så å slå av låsen ville gjort dem uleselige. Låsen blir stående på — bytt PIN-kode i stedet.',
    'error_key_material_lost' => 'Denne enheten holder ikke lenger nøkkelen som åpner de krypterte dataene dine, så en ny PIN-kode gjør dem ikke lesbare igjen. Par denne enheten med en som fortsatt har nøkkelen for å hente dem tilbake.',
    'error_recovery_wrap_stale' => 'Kontopassordet ditt åpner ikke lenger denne applåsen — det ble endret etter at låsen ble satt opp. PIN-koden din virker fortsatt, men det er ingenting bak den om du glemmer den. Koble til kontopassordet på nytt nå.',
    'relink_recovery' => 'Koble til kontopassordet på nytt',
    'relink_modal_heading' => 'Koble til kontopassordet på nytt — bekreft med PIN-kode',
    'relink_recovery_success' => 'Kontopassordet ditt kan gjenopprette denne applåsen igjen.',
];
