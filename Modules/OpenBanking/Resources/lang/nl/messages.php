<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Instellingen',
        'heading' => 'Open banking',
        'subtitle' => 'Haal automatisch transacties op van ASN of SNS via Enable Banking, een externe PSD2-aggregator. Standaard uit.',
        'toggle_label' => 'Open banking inschakelen',
        'toggle_connected' => 'Verbonden met :bank via Enable Banking.',
        'toggle_off_help' => 'Standaard uit. Vereist een eenmalige bevestiging en begeleide installatie.',
        'connect_another' => 'Nog een bank koppelen',
        'credentials_unreadable' => 'De open banking-gegevens die op dit apparaat zijn opgeslagen kunnen niet worden gelezen, dus Beatrax kan je bank niet bereiken.',
        'credentials_unreadable_next' => 'Doorloop de begeleide installatie opnieuw om ze te vervangen. Al geïmporteerde transacties blijven ongemoeid.',
        'reconfirm_body' => 'Je bevestiging is verlopen voordat we de verbinding konden voltooien. Bevestig opnieuw om open banking af te ronden.',
        'reconfirm_button' => 'Bevestig opnieuw om af te ronden',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Open banking beheren',
        'not_connected' => 'Geen bank verbonden. Verbind er een om transacties automatisch te importeren.',
        'expired' => 'Toestemming verlopen — opnieuw verbinden nodig.',
        'revoked' => 'Je bank heeft de verbinding beëindigd — opnieuw verbinden nodig.',
        'connected' => 'Verbonden met :bank via Enable Banking. Laatst gesynchroniseerd :when.',
        'never' => 'nooit',
    ],

    'transparency' => [
        'aggregator_label' => 'Aggregator',
        'bank_label' => 'Bank',
        'consent_status_label' => 'Toestemmingsstatus',
        'pill_expired' => 'Verlopen — opnieuw verbinden',
        'pill_expiring' => 'Verloopt binnenkort',
        'pill_connected' => 'Verbonden',
        'pill_revoked' => 'Beëindigd door je bank — opnieuw verbinden',
        'whats_fetched_label' => 'Wat wordt opgehaald',
        'whats_fetched' => 'Geboekte transacties + saldi, laatste 90 dagen',
        'last_successful_sync_label' => 'Laatste succesvolle synchronisatie',
        'never' => 'Nooit',
        'last_attempt_label' => 'Laatste poging',
        'last_attempt_failed' => ':when — mislukt (:reason)',
        'reason_consent_expired' => 'toestemming verlopen',
        'reason_error' => 'fout',
        'reason_truncated' => 'voortijdig gestopt',
        'reason_nothing_imported' => 'niets kon worden vastgelegd',
        'reason_consent_revoked' => 'beëindigd door je bank',
        'disconnect_button' => 'Loskoppelen',
    ],

    'consent_banner' => [
        'heading' => 'Toestemming verlopen — opnieuw verbinden',
        'heading_revoked' => 'Je bank heeft de verbinding beëindigd',
        'body' => 'Je laatste succesvolle synchronisatie was :when. Verbind opnieuw om automatisch synchroniseren te hervatten.',
        'body_revoked' => 'Je bank of Enable Banking heeft de toegang ingetrokken, dus synchroniseren is gestopt. Je laatste succesvolle synchronisatie was :when. Verbind opnieuw om te hervatten.',
        'never' => 'nooit',
        'reconnect' => 'Opnieuw verbinden',
    ],

    'sync' => [
        'review_import' => 'Import beoordelen',
        'reconnect_first' => 'Eerst opnieuw verbinden',
        'auto_caption' => 'Eén keer per dag wordt automatisch een synchronisatie geprobeerd.',
        'sync_now' => 'Nu synchroniseren',
        'consent_expired' => 'Toestemming verlopen — opnieuw verbinden.',
        'unavailable' => 'Enable Banking is tijdelijk niet beschikbaar. Probeer het zo weer.',
        'new_found' => ':count nieuwe transactie gevonden.|:count nieuwe transacties gevonden.',
        'none' => 'Geen nieuwe transacties.',
        'none_importable' => 'Je bank heeft transacties gestuurd, maar geen daarvan kon worden vastgelegd. Open de importbeoordeling om te zien waarom.',
        'in_progress' => 'Er loopt al een synchronisatie. Probeer het zo opnieuw.',
        'truncated' => 'Je bank had meer transacties dan één synchronisatie kan ophalen, dus deze ronde is voortijdig gestopt. Er is niets als gesynchroniseerd vastgelegd — de volgende synchronisatie begint op hetzelfde punt.',
    ],

    'disconnect' => [
        'heading' => 'Open banking loskoppelen?',
        'body' => 'Dit verwijdert je opgeslagen Enable Banking-inloggegevens en toestemming. Automatisch synchroniseren stopt direct. Transacties die al in Beatrax zijn geïmporteerd, blijven ongewijzigd.',
        'confirm' => 'Loskoppelen',
        'cancel' => 'Verbonden houden',
    ],

    'ics' => [
        'section_label' => 'Bestandsimport — geen inloggegevens opgeslagen',
        'heading' => 'ICS-creditcardafschrift',
        'step_login' => 'Inloggen',
        'step_download' => 'Afschrift downloaden',
        'pdf_statement' => 'PDF-afschrift',
        'step_drop' => 'Sleep het hieronder',
        'drop_zone_label' => 'Sleep je afschrift hierheen',
        'drop_zone_hint' => 'of blader naar een bestand',
        'browse_aria' => 'Blader naar een ICS-afschriftbestand',
        'import_button' => 'Afschrift importeren',
        'validation' => [
            'required' => 'Sleep het ICS-afschrift dat je hebt gedownload van Mijn ICS.',
            'max' => 'Dat bestand is te groot. ICS-PDF-afschriften zijn normaal gesproken kleiner dan 1 MB.',
            'extensions' => 'Dat is geen PDF. Mijn ICS exporteert alleen PDF-afschriften.',
        ],
        'could_not_read' => 'Kon :filename niet lezen. De volledige fout staat in /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Voordat je een externe partij verbindt',
        'body' => 'Als je open banking inschakelt, worden je toestemming voor het bankinloggen en vervolgens je transactie- en saldogegevens rechtstreeks vanaf dit apparaat naar Enable Banking en je bank gestuurd. Beatrax heeft geen server die deze gegevens ziet — Enable Banking en je bank wel. Dit is anders dan elke andere importmethode in Beatrax, die nooit gegevens ergens naartoe stuurt.',
        'acknowledge' => 'Ik begrijp dat mijn transactiegegevens worden gedeeld met Enable Banking en mijn bank.',
        'confirm' => 'Open banking inschakelen',
        'cancel' => 'Annuleren',
    ],

    'wizard' => [
        'heading' => 'Verbind je bank',
        'intro' => 'Beatrax gebruikt je eigen Enable Banking-applicatie, zodat je inloggegevens nooit op een gedeelde server terechtkomen. Dit is een eenmalige installatie per bank.',

        'step1_title' => 'Genereer je lokale sleutelpaar',
        'step1_body' => 'Beatrax genereert een RSA-sleutelpaar op dit apparaat. De privésleutel verlaat het nooit.',
        'generate_keypair' => 'Sleutelpaar genereren',
        'public_key_label' => 'Openbare sleutel',
        'copy_public_key' => 'Openbare sleutel kopiëren',
        'copied' => 'Gekopieerd',
        'redirect_uri_label' => 'Redirect-URI',
        'copy_redirect_uri' => 'Redirect-URI kopiëren',

        'step2_title' => 'Registreer de applicatie in Enable Banking',
        'step2_body' => 'Open het Enable Banking-ontwikkelaarsportaal, maak een applicatie aan en plak de openbare sleutel en redirect-URI uit stap 1.',
        'open_portal' => 'Open Enable Banking-portaal ↗',

        'step3_title' => 'Plak je applicatie-ID',
        'application_id_label' => 'Applicatie-ID',
        'step3_help' => 'Wordt opgeslagen in een lokaal bestand buiten de database, dat alleen jij kunt lezen. Het identificeert je applicatie bij Enable Banking, dus het gaat met elke aanvraag mee — je privésleutel nooit.',

        'step4_title' => 'Kies je bank',
        'via_enable_banking' => 'via Enable Banking',
        'other_institution' => 'Andere instelling',
        'institution_id_placeholder' => 'Instellings-ID',

        'step5_title' => 'Voltooi de toestemming in je browser',
        'step5_body' => 'Klik hieronder om het inlog- en toestemmingsscherm van je bank te openen. Voltooi het inloggen en een eventuele 2-factorstap en kom dan terug naar dit venster om Open Banking af te ronden.',
        'step5_body_touch' => 'Tik hieronder om het inlog- en toestemmingsscherm van je bank te openen. Voltooi het inloggen en een eventuele 2-factorstap, schakel daarna terug naar Beatrax en open dit scherm opnieuw om Open Banking af te ronden.',

        'cancel' => 'Annuleren',
        'continue' => 'Doorgaan →',
        'continue_to_bank' => 'Doorgaan naar :bank →',
        'your_bank' => 'je bank',

        'errors' => [
            'save_keypair_failed' => 'Kon je sleutelpaar niet naar schijf opslaan — controleer de rechten van je secrets-map en probeer het opnieuw.',
            'generate_failed' => 'Kon geen sleutelpaar op dit apparaat genereren — controleer je OpenSSL-configuratie.',
            'export_failed' => 'Kon het gegenereerde sleutelpaar niet exporteren.',
            'read_public_failed' => 'Kon de gegenereerde openbare sleutel niet lezen.',
            'generate_first' => 'Genereer eerst een sleutelpaar voordat je doorgaat.',
            'paste_application_id' => 'Plak de applicatie-ID uit het Enable Banking-portaal voordat je doorgaat.',
            'save_application_id_failed' => 'Kon je applicatie-ID niet naar schijf opslaan — controleer de rechten van je secrets-map en probeer het opnieuw.',
            'choose_bank' => 'Kies een bank voordat je doorgaat.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Voltooi eerst de Open Banking-installatiewizard.',
        'no_bank_chosen' => 'Kies een bank voordat je verbindt.',
        'no_consent_url' => 'Enable Banking heeft geen toestemmings-URL geretourneerd.',
        'unparseable_consent_url' => 'Enable Banking heeft een onleesbare toestemmings-URL geretourneerd.',
        'non_public_consent_host' => 'Enable Banking heeft een niet-openbare toestemmingshost geretourneerd.',
        'unsafe_consent_url' => 'Enable Banking heeft een onveilige toestemmings-URL geretourneerd.',
        'no_authorization_code' => 'De Enable Banking-callback heeft geen autorisatiecode geretourneerd.',
        'no_session_id' => 'Enable Banking heeft geen sessie-ID geretourneerd.',
        'bank_not_linked' => 'Die bank is niet gekoppeld op dit apparaat. Koppel hem opnieuw om het synchroniseren te hervatten.',
        'oauth_state_mismatch' => 'Deze koppelingslink is verlopen of al gebruikt. Begin opnieuw met het koppelen van je bank.',
    ],
];
