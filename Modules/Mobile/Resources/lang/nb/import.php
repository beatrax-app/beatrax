<?php

declare(strict_types=1);

return [
    'page_title' => 'Importer fra en annen enhet',

    'heading' => 'Importer fra en annen enhet',
    'subtitle' => 'Sett opp denne telefonen med egen konto og lås, og par den deretter med den andre enheten din for å hente inn historikken.',

    'username' => 'Brukernavn',
    'password' => 'Passord',
    'password_help' => 'Minst 12 tegn — det finnes ingen tilbakestilling av passord, bare gjenopprettingskoder.',
    'confirm_password' => 'Bekreft passord',
    'pin' => 'PIN-kode for applås',
    'pin_help' => '4-10 sifre — låser opp denne enheten.',
    'confirm_pin' => 'Bekreft PIN-kode',
    'continue' => 'Fortsett',

    'failed_heading' => 'Oppsettet ble ikke fullført',
    'failed_body' => 'Kontoen din ble opprettet, men vi kunne ikke fullføre oppsettet av denne enheten. Du kan trygt prøve igjen.',
    'try_again' => 'Prøv igjen',

    'recovery_heading' => 'Lagre disse gjenopprettingskodene',
    'recovery_body' => 'Skriv dem ut, eller lagre dem på et trygt sted. De vises ikke igjen.',
    'already_heading' => 'Denne enheten er allerede satt opp',
    'already_body' => 'Kontoen din finnes allerede på denne enheten. Gå videre til paringen for å koble den til de andre enhetene dine.',
    'recovery_download' => 'Last ned som .txt',
    'recovery_copy' => 'Kopier koder',
    'recovery_copied' => 'Kopiert',
    'recovery_saved' => 'Lagret i nedlastingene dine.',
    'recovery_confirm' => 'Jeg har lagret disse kodene på et trygt sted.',
    'continue_to_pairing' => 'Fortsett til paringen',

    'errors' => [
        'passwords_mismatch' => 'Passordene er ikke like.',
        'password_length' => 'Bruk minst 12 tegn.',
        'pin_length' => 'PIN-koden må ha minst 4 sifre.',
        'pins_mismatch' => 'PIN-kodene er ikke like. Prøv igjen.',
        'session_expired' => 'Økten din utløp før oppsettet ble fullført. Skriv inn PIN-koden og passordet ditt på nytt.',
        'retry_failed' => 'Oppsettet av denne enheten kunne fortsatt ikke fullføres. Prøv igjen.',
        'account_failed' => 'Kontoen kunne ikke opprettes.',
    ],
];
