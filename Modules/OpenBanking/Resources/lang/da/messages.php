<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Indstillinger',
        'heading' => 'Open banking',
        'subtitle' => 'Hent automatisk transaktioner fra ASN eller SNS gennem Enable Banking, en PSD2-aggregator fra tredjepart. Slået fra som standard.',
        'toggle_label' => 'Aktivér open banking',
        'toggle_connected' => 'Tilsluttet :bank via Enable Banking.',
        'toggle_off_help' => 'Slået fra som standard. Kræver en engangsgodkendelse og en guidet opsætning.',
        'reconfirm_body' => 'Din godkendelse udløb, før tilslutningen kunne gøres færdig. Bekræft igen for at gøre aktiveringen af open banking færdig.',
        'reconfirm_button' => 'Bekræft igen for at gøre aktiveringen færdig',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Administrér open banking',
        'not_connected' => 'Ingen bank tilsluttet. Tilslut en for at importere transaktioner automatisk.',
        'expired' => 'Samtykket er udløbet — du skal tilslutte igen.',
        'connected' => 'Tilsluttet :bank via Enable Banking. Sidst synkroniseret :when.',
        'never' => 'aldrig',
    ],

    'transparency' => [
        'aggregator_label' => 'Aggregator',
        'bank_label' => 'Bank',
        'consent_status_label' => 'Status for samtykke',
        'pill_expired' => 'Udløbet — tilslut igen',
        'pill_expiring' => 'Udløber snart',
        'pill_connected' => 'Tilsluttet',
        'whats_fetched_label' => 'Hvad der hentes',
        'whats_fetched' => 'Bogførte transaktioner + saldi, seneste 90 dage',
        'last_successful_sync_label' => 'Seneste vellykkede synkronisering',
        'never' => 'Aldrig',
        'last_attempt_label' => 'Seneste forsøg',
        'last_attempt_failed' => ':when — mislykkedes (:reason)',
        'reason_consent_expired' => 'samtykket er udløbet',
        'reason_error' => 'fejl',
        'disconnect_button' => 'Afbryd forbindelsen',
    ],

    'consent_banner' => [
        'heading' => 'Samtykket er udløbet — tilslut igen',
        'body' => 'Din seneste vellykkede synkronisering var :when. Tilslut igen for at genoptage den automatiske synkronisering.',
        'never' => 'aldrig',
        'reconnect' => 'Tilslut igen',
    ],

    'sync' => [
        'review_import' => 'Gennemgå importen',
        'reconnect_first' => 'Tilslut igen først',
        'auto_caption' => 'Synkroniserer automatisk en gang om dagen.',
        'sync_now' => 'Synkronisér nu',

        'consent_expired' => 'Samtykket er udløbet — tilslut igen.',
        'unavailable' => 'Enable Banking er midlertidigt utilgængelig. Prøv igen om lidt.',
        'new_found' => ':count nye transaktioner fundet.',
        'none' => 'Ingen nye transaktioner.',
    ],

    'disconnect' => [
        'heading' => 'Vil du afbryde forbindelsen til open banking?',
        'body' => 'Det fjerner dine gemte Enable Banking-oplysninger og dit samtykke. Den automatiske synkronisering stopper med det samme. Transaktioner, der allerede er importeret i Beatrax, berøres ikke.',
        'confirm' => 'Afbryd forbindelsen',
        'cancel' => 'Behold forbindelsen',
    ],

    'ics' => [
        'section_label' => 'Filimport — ingen loginoplysninger gemmes',
        'heading' => 'ICS-kreditkortudtog',
        'step_login' => 'Log ind',
        'step_download' => 'Hent udtoget',
        'pdf_statement' => 'PDF-udtog',
        'step_drop' => 'Slip det nedenfor',
        'drop_zone_label' => 'Slip din udtogsfil her',
        'drop_zone_hint' => 'eller find en fil',
        'browse_aria' => 'Find en ICS-udtogsfil',
        'import_button' => 'Importér udtog',
        'validation' => [
            'required' => 'Slip det ICS-udtog, du hentede fra Mijn ICS.',
            'max' => 'Filen er for stor. ICS PDF-udtog fylder normalt under 1 MB hver.',
            'extensions' => 'Det er ikke en PDF. Mijn ICS eksporterer kun udtog som PDF.',
        ],
        'could_not_read' => 'Kunne ikke læse :filename. Hele fejlen står i /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Før du tilslutter en tredjepart',
        'body' => 'Når du aktiverer open banking, sendes dit samtykke til banklogin og derefter dine transaktions- og saldooplysninger direkte fra denne enhed til Enable Banking og din bank. Beatrax driver ingen server, der ser disse oplysninger — men det gør Enable Banking og din bank. Det adskiller sig fra alle andre importmetoder i Beatrax, som aldrig sender data nogen steder hen.',
        'acknowledge' => 'Jeg forstår, at mine transaktionsoplysninger deles med Enable Banking og min bank.',
        'confirm' => 'Aktivér open banking',
        'cancel' => 'Annullér',
    ],

    'wizard' => [
        'heading' => 'Tilslut din bank',
        'intro' => 'Beatrax bruger din egen Enable Banking-applikation, så dine oplysninger aldrig rører en delt server. Det er en engangsopsætning pr. bank.',

        'step1_title' => 'Generér dit lokale nøglepar',
        'step1_body' => 'Beatrax genererer et RSA-nøglepar på denne enhed. Den private nøgle forlader den aldrig.',
        'generate_keypair' => 'Generér nøglepar',
        'public_key_label' => 'Offentlig nøgle',
        'copy_public_key' => 'Kopiér offentlig nøgle',
        'copied' => 'Kopieret',
        'redirect_uri_label' => 'Omdirigerings-URI',
        'copy_redirect_uri' => 'Kopiér omdirigerings-URI',

        'step2_title' => 'Registrér applikationen i Enable Banking',
        'step2_body' => 'Åbn udviklerportalen hos Enable Banking, opret en applikation, og indsæt den offentlige nøgle og omdirigerings-URI fra trin 1.',
        'open_portal' => 'Åbn Enable Banking-portalen ↗',

        'step3_title' => 'Indsæt dit applikations-id',
        'application_id_label' => 'Applikations-id',
        'step3_help' => 'Det gemmes i en lokal fil uden for databasen med restriktive rettigheder og forlader aldrig denne enhed.',

        'step4_title' => 'Vælg din bank',
        'via_enable_banking' => 'via Enable Banking',
        'other_institution' => 'Andet institut',
        'institution_id_placeholder' => 'Institutions-id',

        'step5_title' => 'Gennemfør samtykket i din browser',
        'step5_body' => 'Klik nedenfor for at åbne din banks login- og samtykkeside. Gennemfør login og et eventuelt totrinstrin, hvorefter du automatisk føres tilbage hertil for at gøre aktiveringen af Open Banking færdig.',

        'cancel' => 'Annullér',
        'continue' => 'Fortsæt →',
        'continue_to_bank' => 'Fortsæt til :bank →',
        'your_bank' => 'din bank',

        'errors' => [
            'save_keypair_failed' => 'Dit nøglepar kunne ikke gemmes på disken — tjek rettighederne til mappen med hemmeligheder, og prøv igen.',
            'generate_failed' => 'Der kunne ikke genereres et nøglepar på denne enhed — tjek din OpenSSL-konfiguration.',
            'export_failed' => 'Det genererede nøglepar kunne ikke eksporteres.',
            'read_public_failed' => 'Den genererede offentlige nøgle kunne ikke læses.',
            'generate_first' => 'Generér et nøglepar, før du fortsætter.',
            'paste_application_id' => 'Indsæt applikations-id fra Enable Banking-portalen, før du fortsætter.',
            'save_application_id_failed' => 'Dit applikations-id kunne ikke gemmes på disken — tjek rettighederne til mappen med hemmeligheder, og prøv igen.',
            'choose_bank' => 'Vælg en bank, før du fortsætter.',
        ],
    ],

    'alert' => [
        'reconsent' => 'Tilslut din bank igen',
    ],

    'errors' => [
        'wizard_incomplete' => 'Gør opsætningsguiden til Open Banking færdig først.',
        'no_bank_chosen' => 'Vælg en bank, før du tilslutter.',
        'no_consent_url' => 'Enable Banking returnerede ingen samtykke-URL.',
        'unparseable_consent_url' => 'Enable Banking returnerede en samtykke-URL, der ikke kunne fortolkes.',
        'non_public_consent_host' => 'Enable Banking returnerede en samtykkevært, der ikke er offentlig.',
        'unsafe_consent_url' => 'Enable Banking returnerede en usikker samtykke-URL.',
        'no_authorization_code' => 'Tilbagekaldet fra Enable Banking indeholdt ingen autorisationskode.',
        'no_session_id' => 'Enable Banking returnerede ikke noget sessions-id.',
    ],
];
