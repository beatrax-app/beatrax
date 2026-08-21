<?php

declare(strict_types=1);

return [
    'page_title' => 'Importeren vanaf een ander apparaat',

    'heading' => 'Importeren vanaf een ander apparaat',
    'subtitle' => 'Stel deze telefoon in met een eigen account en vergrendeling, en koppel hem daarna met je andere apparaat om je geschiedenis op te halen.',

    'username' => 'Gebruikersnaam',
    'password' => 'Wachtwoord',
    'password_help' => 'Minimaal 12 tekens — er is geen wachtwoordherstel, alleen herstelcodes.',
    'confirm_password' => 'Bevestig wachtwoord',

    'requirements_aria' => 'Wachtwoordvereisten',
    'req_length' => 'Minimaal 12 tekens',
    'req_match' => 'Beide wachtwoorden komen overeen',
    'req_met' => '(voldaan)',
    'req_unmet' => '(nog niet voldaan)',

    'pin' => 'App-vergrendeling pincode',
    'pin_help' => '6-10 cijfers — ontgrendelt dit apparaat.',
    'confirm_pin' => 'Bevestig pincode',
    'continue' => 'Doorgaan',

    'failed_heading' => 'Instellen niet voltooid',
    'failed_body' => 'Je account is aangemaakt, maar we konden het instellen van dit apparaat niet voltooien. Je kunt het veilig opnieuw proberen.',
    'try_again' => 'Opnieuw proberen',

    'recovery_heading' => 'Bewaar deze herstelcodes',
    'recovery_body' => 'Print deze of bewaar ze op een veilige plek. Ze worden niet nog eens getoond.',
    'already_heading' => 'Dit apparaat is al ingesteld',
    'already_body' => 'Je account bestaat al op dit apparaat. Ga verder naar koppelen om het met je andere apparaten te verbinden.',
    'recovery_download' => 'Downloaden als .txt',
    'recovery_copy' => 'Codes kopiëren',
    'recovery_copied' => 'Gekopieerd',
    'recovery_copy_failed' => 'Kopiëren is niet gelukt. Schrijf de codes op.',
    'recovery_saved' => 'Opgeslagen in je downloads.',
    'recovery_share_title' => 'Beatrax-herstelcodes',
    'recovery_share_message' => 'Bewaar deze op een veilige plek.',
    'recovery_save_failed' => 'Het bestand kon niet worden opgeslagen. Schrijf de codes op.',
    'recovery_confirm' => 'Ik heb deze codes op een veilige plek bewaard.',
    'continue_to_pairing' => 'Doorgaan naar koppelen',

    'errors' => [
        'username_required' => 'Gebruikersnaam is verplicht.',
        'passwords_mismatch' => 'Wachtwoorden komen niet overeen.',
        'password_length' => 'Gebruik minimaal 12 tekens.',
        'pin_length' => 'De pincode moet minimaal 6 cijfers hebben.',
        'pins_mismatch' => 'De pincodes komen niet overeen. Probeer het opnieuw.',
        'session_expired' => 'Je sessie is verlopen voordat het instellen was voltooid. Voer je pincode en wachtwoord opnieuw in.',
        'retry_failed' => 'Kon dit apparaat nog steeds niet volledig instellen. Probeer het opnieuw.',
        'account_failed' => 'Kon het account niet aanmaken.',
    ],
];
