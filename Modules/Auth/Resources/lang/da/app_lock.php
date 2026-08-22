<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Biometrisk oplåsning er ikke tilgængelig på denne enhed.',
    'error_enroll_unprotected' => 'Biometrisk oplåsning kræver et nøglelager i operativsystemet, og denne installation har ingen. Tilmelding ville efterlade oplåsningsnøglen læsbar ved siden af dine data, så det tilbydes ikke her.',
    'error_enroll_locked' => 'Lås appen op, før du registrerer enheden.',
    'error_enroll_failed' => 'Din enhed afviste at gemme nøglen. Biometrisk oplåsning er ikke tilgængelig.',
    'heading' => 'Applås',

    'moved_help' => 'Din PIN-kode, tidsrum for automatisk lås og biometrisk oplåsning ligger sammen med denne enheds synkroniseringsindstillinger.',
    'moved_cta' => 'Åbn Synkronisering og enhed',

    'toggle_label' => 'Lås appen med PIN-kode',
    'toggle_description' => 'Erstatter det daglige login med en PIN-kode. Sessioner er aktive i 30 dage.',

    'setup_heading' => 'Angiv en PIN-kode for at slå låsen til',
    'new_pin_label' => 'Ny PIN-kode (6–10 cifre)',
    'confirm_pin_label' => 'Bekræft PIN-kode',
    'account_password_label' => 'Kontoadgangskode',
    'account_password_note' => '(kræves for at oprette en gendannelsesnøgle)',
    'account_password_placeholder' => 'Din kontoadgangskode',
    'set_pin' => 'Angiv PIN-kode',

    'pin_row_label' => 'PIN-kode',
    'pin_row_description' => 'Skift din nuværende PIN-kode.',
    'change_pin' => 'Skift PIN-kode',
    'forgot_pin_link' => 'Har du glemt din PIN-kode? Nulstil den med din kontoadgangskode.',

    'biometric_enrolled_description' => 'Denne enhed er registreret til biometrisk oplåsning.',
    'biometric_enroll_description' => 'Registrér denne enhed for at låse op med biometri.',
    'remove' => 'Fjern',
    'enroll' => 'Registrér',
    'biometric_unavailable' => 'Biometrisk oplåsning er ikke tilgængelig på denne enhed.',

    'deenroll_modal_heading' => 'Fjern biometrisk oplåsning — bekræft med PIN-kode',
    'current_pin_label' => 'Nuværende PIN-kode',
    'remove_biometric' => 'Fjern biometri',
    'keep_biometric' => 'Behold biometri',

    'auto_lock' => 'Lås automatisk efter',
    'idle_1' => '1 minut',
    'idle_5' => '5 minutter',
    'idle_15' => '15 minutter',
    'idle_30' => '30 minutter',

    'disable_modal_heading' => 'Slå applås fra — bekræft med PIN-kode',
    'disable_lock' => 'Slå lås fra',
    'keep_lock' => 'Behold applås',

    'forgot_modal_heading' => 'Nulstil PIN-kode — bekræft med kontoadgangskode',
    'forgot_modal_body' => 'Din kontoadgangskode gendanner låsenøglen, så du aldrig mister data, når du nulstiller PIN-koden.',
    'confirm_new_pin_label' => 'Bekræft ny PIN-kode',
    'reset_pin' => 'Nulstil PIN-kode',
    'cancel' => 'Annullér',

    'change_modal_heading' => 'Skift PIN-kode — bekræft med nuværende PIN-kode',
    'keep_pin' => 'Behold PIN-kode',

    'error_pin_too_short' => 'PIN-koden skal have mindst 6 cifre.',
    'error_pin_mismatch' => 'PIN-koderne er ikke ens. Prøv igen.',
    'error_pin_required' => 'Indtast din PIN-kode.',
    'error_pin_incorrect' => 'Forkert PIN-kode.',
    'error_account_password_required' => 'Indtast din kontoadgangskode.',
    'error_account_password' => 'Forkert kontoadgangskode.',
    'change_pin_success' => 'Din krypteringsnøgle er sikret på ny med din nye PIN-kode.',
    'error_forgot_failed' => 'Nulstilling af PIN-koden mislykkedes — gendannelsesnøglen er ikke tilgængelig.',
    'error_enable_first' => 'Slå PIN-låsen til, før du registrerer biometri.',
    'error_disable_blocked_by_encryption' => 'Dine noter og modpartsoplysninger er krypteret med den nøgle, denne app-lås holder, så at slå låsen fra ville gøre dem ulæselige. Låsen bliver på — skift din PIN-kode i stedet.',
    'error_key_material_lost' => 'Denne enhed har ikke længere nøglen, der åbner dine krypterede data, så en ny PIN-kode gør dem ikke læselige igen. Par denne enhed med en, der stadig har nøglen, for at gendanne dem.',
    'error_recovery_wrap_stale' => 'Din kontoadgangskode åbner ikke længere denne applås — den blev ændret, efter låsen blev sat op. Din PIN-kode virker stadig, men der er intet bag den, hvis du glemmer den. Tilknyt din kontoadgangskode igen nu.',
    'relink_recovery' => 'Tilknyt kontoadgangskode igen',
    'relink_modal_heading' => 'Tilknyt kontoadgangskode igen — bekræft med PIN-kode',
    'relink_recovery_success' => 'Din kontoadgangskode kan gendanne denne applås igen.',
];
