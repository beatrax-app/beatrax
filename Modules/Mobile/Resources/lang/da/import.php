<?php

declare(strict_types=1);

return [
    'page_title' => 'Importér fra en anden enhed',

    'heading' => 'Importér fra en anden enhed',
    'subtitle' => 'Sæt denne telefon op med sin egen konto og lås, og par den derefter med din anden enhed for at hente din historik ind.',

    'username' => 'Brugernavn',
    'password' => 'Adgangskode',
    'password_help' => 'Mindst 12 tegn — der findes ingen nulstilling af adgangskoden, kun gendannelseskoder.',
    'confirm_password' => 'Bekræft adgangskode',

    'requirements_aria' => 'Krav til adgangskoden',
    'req_length' => 'Mindst 12 tegn',
    'req_match' => 'Begge adgangskoder er ens',
    'req_met' => '(opfyldt)',
    'req_unmet' => '(ikke opfyldt endnu)',

    'pin' => 'PIN-kode til app-lås',
    'pin_help' => '6-10 cifre — låser denne enhed op.',
    'confirm_pin' => 'Bekræft PIN-kode',
    'continue' => 'Fortsæt',

    'failed_heading' => 'Opsætningen blev ikke færdig',
    'failed_body' => 'Din konto blev oprettet, men vi kunne ikke gøre opsætningen af denne enhed færdig. Du kan trygt prøve igen.',
    'try_again' => 'Prøv igen',

    'recovery_heading' => 'Gem disse gendannelseskoder',
    'recovery_body' => 'Print dem, eller gem dem et sikkert sted. De bliver ikke vist igen.',
    'already_heading' => 'Denne enhed er allerede sat op',
    'already_body' => 'Din konto findes allerede på denne enhed. Gå videre til parringen for at forbinde den med dine andre enheder.',
    'recovery_download' => 'Hent som .txt',
    'recovery_copy' => 'Kopiér koder',
    'recovery_copied' => 'Kopieret',
    'recovery_copy_failed' => 'Kunne ikke kopiere. Skriv koderne ned i stedet.',
    'recovery_saved' => 'Gemt i dine downloads.',
    'recovery_share_title' => 'Beatrax-gendannelseskoder',
    'recovery_share_message' => 'Opbevar dem et sikkert sted.',
    'recovery_save_failed' => 'Filen kunne ikke gemmes. Skriv koderne ned i stedet.',
    'recovery_confirm' => 'Jeg har gemt disse koder et sikkert sted.',
    'continue_to_pairing' => 'Fortsæt til parringen',

    'errors' => [
        'username_required' => 'Brugernavn er påkrævet.',
        'passwords_mismatch' => 'Adgangskoderne er ikke ens.',
        'password_length' => 'Brug mindst 12 tegn.',
        'pin_length' => 'PIN-koden skal have mindst 6 cifre.',
        'pin_digits' => 'PIN-koden skal have 6 til 10 cifre — kun tal.',
        'pins_mismatch' => 'PIN-koderne er ikke ens. Prøv igen.',
        'session_expired' => 'Din session udløb, før opsætningen blev færdig. Indtast din PIN-kode og adgangskode igen.',
        'retry_failed' => 'Opsætningen af denne enhed kunne stadig ikke gøres færdig. Prøv igen.',
        'account_failed' => 'Kontoen kunne ikke oprettes.',
    ],
];
