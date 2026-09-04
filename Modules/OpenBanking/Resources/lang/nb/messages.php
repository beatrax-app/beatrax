<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Innstillinger',
        'heading' => 'Open banking',
        'subtitle' => 'Hent transaksjoner automatisk fra ASN eller SNS gjennom Enable Banking, en PSD2-aggregator fra tredjepart. Av som standard.',
        'toggle_label' => 'Slå på open banking',
        'toggle_connected' => 'Koblet til :bank via Enable Banking.',
        'toggle_off_help' => 'Av som standard. Krever en engangsgodkjenning og et veiledet oppsett.',
        'credentials_unreadable' => 'Open banking-opplysningene som er lagret på denne enheten, kan ikke leses, så Beatrax får ikke kontakt med banken din.',
        'credentials_unreadable_next' => 'Kjør det veiledede oppsettet på nytt for å erstatte dem. Transaksjoner som allerede er importert, påvirkes ikke.',
        'reconfirm_body' => 'Godkjenningen din utløp før tilkoblingen ble fullført. Bekreft på nytt for å fullføre aktiveringen av open banking.',
        'reconfirm_button' => 'Bekreft på nytt for å fullføre aktiveringen',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Administrer open banking',
        'not_connected' => 'Ingen bank tilkoblet. Koble til en for å importere transaksjoner automatisk.',
        'expired' => 'Samtykket er utløpt — du må koble til på nytt.',
        'revoked' => 'Banken din har avsluttet tilkoblingen — koble til på nytt.',
        'connected' => 'Koblet til :bank via Enable Banking. Sist synkronisert :when.',
        'never' => 'aldri',
    ],

    'transparency' => [
        'aggregator_label' => 'Aggregator',
        'bank_label' => 'Bank',
        'consent_status_label' => 'Status for samtykke',
        'pill_expired' => 'Utløpt — koble til på nytt',
        'pill_expiring' => 'Utløper snart',
        'pill_connected' => 'Tilkoblet',
        'pill_revoked' => 'Avsluttet av banken din — koble til på nytt',
        'whats_fetched_label' => 'Hva som hentes',
        'whats_fetched' => 'Bokførte transaksjoner + saldoer, siste 90 dager',
        'last_successful_sync_label' => 'Siste vellykkede synkronisering',
        'never' => 'Aldri',
        'last_attempt_label' => 'Siste forsøk',
        'last_attempt_failed' => ':when — mislyktes (:reason)',
        'reason_consent_expired' => 'samtykket er utløpt',
        'reason_error' => 'feil',
        'reason_truncated' => 'stoppet for tidlig',
        'reason_nothing_imported' => 'ingenting kunne registreres',
        'reason_consent_revoked' => 'avsluttet av banken din',
        'disconnect_button' => 'Koble fra',
    ],

    'consent_banner' => [
        'heading' => 'Samtykket er utløpt — koble til på nytt',
        'heading_revoked' => 'Banken din har avsluttet tilkoblingen',
        'body' => 'Den siste vellykkede synkroniseringen din var :when. Koble til på nytt for å gjenoppta automatisk synkronisering.',
        'body_revoked' => 'Banken din eller Enable Banking har trukket tilbake tilgangen, så synkroniseringen har stoppet. Din siste vellykkede synkronisering var :when. Koble til på nytt for å fortsette.',
        'never' => 'aldri',
        'reconnect' => 'Koble til på nytt',
    ],

    'sync' => [
        'review_import' => 'Gjennomgå importen',
        'reconnect_first' => 'Koble til på nytt først',
        'auto_caption' => 'Synkroniserer automatisk én gang om dagen.',
        'sync_now' => 'Synkroniser nå',

        'consent_expired' => 'Samtykket er utløpt — koble til på nytt.',
        'unavailable' => 'Enable Banking er midlertidig utilgjengelig. Prøv igjen om litt.',
        'new_found' => ':count ny transaksjon funnet.|:count nye transaksjoner funnet.',
        'none' => 'Ingen nye transaksjoner.',
        'none_importable' => 'Banken din sendte transaksjoner, men ingen av dem kunne registreres. Åpne gjennomgangen av importen for å se hvorfor.',
        'in_progress' => 'En synkronisering pågår allerede. Prøv igjen om et øyeblikk.',
        'truncated' => 'Banken din hadde flere transaksjoner enn én synkronisering kan hente, så denne kjøringen stoppet for tidlig. Ingenting er registrert som synkronisert — neste synkronisering starter på samme sted.',
    ],

    'disconnect' => [
        'heading' => 'Vil du koble fra open banking?',
        'body' => 'Dette fjerner de lagrede Enable Banking-opplysningene dine og samtykket ditt. Den automatiske synkroniseringen stopper umiddelbart. Transaksjoner som allerede er importert til Beatrax, påvirkes ikke.',
        'confirm' => 'Koble fra',
        'cancel' => 'Behold tilkoblingen',
    ],

    'ics' => [
        'section_label' => 'Filimport — ingen påloggingsopplysninger lagres',
        'heading' => 'ICS-kredittkortutskrift',
        'step_login' => 'Logg inn',
        'step_download' => 'Last ned utskriften',
        'pdf_statement' => 'PDF-utskrift',
        'step_drop' => 'Slipp den nedenfor',
        'drop_zone_label' => 'Slipp utskriftsfilen din her',
        'drop_zone_hint' => 'eller bla etter en fil',
        'browse_aria' => 'Bla etter en ICS-utskriftsfil',
        'import_button' => 'Importer utskrift',
        'validation' => [
            'required' => 'Slipp ICS-utskriften du lastet ned fra Mijn ICS.',
            'max' => 'Filen er for stor. ICS PDF-utskrifter er normalt under 1 MB hver.',
            'extensions' => 'Det er ikke en PDF. Mijn ICS eksporterer bare utskrifter som PDF.',
        ],
        'could_not_read' => 'Kunne ikke lese :filename. Hele feilen ligger i /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Før du kobler til en tredjepart',
        'body' => 'Når du slår på open banking, sendes samtykket ditt til bankpålogging, og deretter transaksjons- og saldoopplysningene dine, direkte fra denne enheten til Enable Banking og banken din. Beatrax driver ingen server som ser disse opplysningene — men det gjør Enable Banking og banken din. Dette skiller seg fra alle andre importmåter i Beatrax, som aldri sender data noe sted.',
        'acknowledge' => 'Jeg forstår at transaksjonsopplysningene mine deles med Enable Banking og banken min.',
        'confirm' => 'Slå på open banking',
        'cancel' => 'Avbryt',
    ],

    'wizard' => [
        'heading' => 'Koble til banken din',
        'intro' => 'Beatrax bruker din egen Enable Banking-applikasjon, slik at opplysningene dine aldri er innom en delt server. Dette er et engangsoppsett per bank.',

        'step1_title' => 'Generer ditt lokale nøkkelpar',
        'step1_body' => 'Beatrax genererer et RSA-nøkkelpar på denne enheten. Den private nøkkelen forlater den aldri.',
        'generate_keypair' => 'Generer nøkkelpar',
        'public_key_label' => 'Offentlig nøkkel',
        'copy_public_key' => 'Kopier offentlig nøkkel',
        'copied' => 'Kopiert',
        'redirect_uri_label' => 'Omdirigerings-URI',
        'copy_redirect_uri' => 'Kopier omdirigerings-URI',

        'step2_title' => 'Registrer applikasjonen i Enable Banking',
        'step2_body' => 'Åpne utviklerportalen hos Enable Banking, opprett en applikasjon, og lim inn den offentlige nøkkelen og omdirigerings-URI fra trinn 1.',
        'open_portal' => 'Åpne Enable Banking-portalen ↗',

        'step3_title' => 'Lim inn applikasjons-ID-en din',
        'application_id_label' => 'Applikasjons-ID',
        'step3_help' => 'Dette lagres i en lokal fil utenfor databasen med restriktive rettigheter og forlater aldri denne enheten.',

        'step4_title' => 'Velg banken din',
        'via_enable_banking' => 'via Enable Banking',
        'other_institution' => 'Annen institusjon',
        'institution_id_placeholder' => 'Institusjons-ID',

        'step5_title' => 'Fullfør samtykket i nettleseren din',
        'step5_body' => 'Klikk nedenfor for å åpne bankens pålogging og samtykkeside. Fullfør påloggingen og et eventuelt tofaktortrinn, så føres du automatisk tilbake hit for å fullføre aktiveringen av Open Banking.',
        // i18n-review: nb · step5_body_touch — the same line for a touch
        // screen; check the verb governs this case.
        'step5_body_touch' => 'Trykk nedenfor for å åpne bankens pålogging og samtykkeside. Fullfør påloggingen og et eventuelt tofaktortrinn, så føres du automatisk tilbake hit for å fullføre aktiveringen av Open Banking.',

        'cancel' => 'Avbryt',
        'continue' => 'Fortsett →',
        'continue_to_bank' => 'Fortsett til :bank →',
        'your_bank' => 'banken din',

        'errors' => [
            'save_keypair_failed' => 'Kunne ikke lagre nøkkelparet ditt på disk — sjekk rettighetene til mappen med hemmeligheter og prøv igjen.',
            'generate_failed' => 'Kunne ikke generere et nøkkelpar på denne enheten — sjekk OpenSSL-konfigurasjonen din.',
            'export_failed' => 'Kunne ikke eksportere det genererte nøkkelparet.',
            'read_public_failed' => 'Kunne ikke lese den genererte offentlige nøkkelen.',
            'generate_first' => 'Generer et nøkkelpar før du fortsetter.',
            'paste_application_id' => 'Lim inn applikasjons-ID-en fra Enable Banking-portalen før du fortsetter.',
            'save_application_id_failed' => 'Kunne ikke lagre applikasjons-ID-en din på disk — sjekk rettighetene til mappen med hemmeligheter og prøv igjen.',
            'choose_bank' => 'Velg en bank før du fortsetter.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Fullfør oppsettsveiviseren for Open Banking først.',
        'no_bank_chosen' => 'Velg en bank før du kobler til.',
        'no_consent_url' => 'Enable Banking returnerte ingen samtykke-URL.',
        'unparseable_consent_url' => 'Enable Banking returnerte en samtykke-URL som ikke kunne tolkes.',
        'non_public_consent_host' => 'Enable Banking returnerte en samtykkevert som ikke er offentlig.',
        'unsafe_consent_url' => 'Enable Banking returnerte en usikker samtykke-URL.',
        'no_authorization_code' => 'Tilbakekallet fra Enable Banking inneholdt ingen autorisasjonskode.',
        'no_session_id' => 'Enable Banking returnerte ingen økt-ID.',
        'oauth_state_mismatch' => 'Denne tilkoblingslenken er utløpt eller allerede brukt. Start tilkoblingen til banken på nytt.',
    ],
];
