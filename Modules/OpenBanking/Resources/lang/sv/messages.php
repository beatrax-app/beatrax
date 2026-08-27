<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Inställningar',
        'heading' => 'Open banking',
        'subtitle' => 'Hämta transaktioner automatiskt från ASN eller SNS via Enable Banking, en PSD2-aggregator från tredje part. Avstängt som standard.',
        'toggle_label' => 'Aktivera open banking',
        'toggle_connected' => 'Ansluten till :bank via Enable Banking.',
        'toggle_off_help' => 'Avstängt som standard. Kräver ett engångsgodkännande och en guidad konfiguration.',
        'reconfirm_body' => 'Ditt godkännande gick ut innan anslutningen hann bli klar. Bekräfta på nytt för att slutföra aktiveringen av open banking.',
        'reconfirm_button' => 'Bekräfta på nytt för att slutföra aktiveringen',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Hantera open banking',
        'not_connected' => 'Ingen bank ansluten. Anslut en för att importera transaktioner automatiskt.',
        'expired' => 'Samtycket har gått ut — du behöver ansluta på nytt.',
        'connected' => 'Ansluten till :bank via Enable Banking. Senast synkad :when.',
        'never' => 'aldrig',
    ],

    'transparency' => [
        'aggregator_label' => 'Aggregator',
        'bank_label' => 'Bank',
        'consent_status_label' => 'Status för samtycke',
        'pill_expired' => 'Utgånget — anslut på nytt',
        'pill_expiring' => 'Går snart ut',
        'pill_connected' => 'Ansluten',
        'whats_fetched_label' => 'Vad som hämtas',
        'whats_fetched' => 'Bokförda transaktioner + saldon, senaste 90 dagarna',
        'last_successful_sync_label' => 'Senaste lyckade synkning',
        'never' => 'Aldrig',
        'last_attempt_label' => 'Senaste försök',
        'last_attempt_failed' => ':when — misslyckades (:reason)',
        'reason_consent_expired' => 'samtycket har gått ut',
        'reason_error' => 'fel',
        'disconnect_button' => 'Koppla från',
    ],

    'consent_banner' => [
        'heading' => 'Samtycket har gått ut — anslut på nytt',
        'body' => 'Din senaste lyckade synkning var :when. Anslut på nytt för att återuppta automatisk synkning.',
        'never' => 'aldrig',
        'reconnect' => 'Återanslut',
    ],

    'sync' => [
        'review_import' => 'Granska importen',
        'reconnect_first' => 'Anslut på nytt först',
        'auto_caption' => 'Synkar automatiskt en gång om dagen.',
        'sync_now' => 'Synka nu',

        'consent_expired' => 'Samtycket har gått ut — anslut på nytt.',
        'unavailable' => 'Enable Banking är tillfälligt otillgängligt. Försök igen om en stund.',
        'new_found' => ':count ny transaktion hittades.|:count nya transaktioner hittades.',
        'none' => 'Inga nya transaktioner.',
    ],

    'disconnect' => [
        'heading' => 'Vill du koppla från open banking?',
        'body' => 'Detta tar bort dina sparade Enable Banking-uppgifter och ditt samtycke. Den automatiska synkningen upphör omedelbart. Transaktioner som redan importerats till Beatrax påverkas inte.',
        'confirm' => 'Koppla från',
        'cancel' => 'Behåll anslutningen',
    ],

    'ics' => [
        'section_label' => 'Filimport — inga inloggningsuppgifter sparas',
        'heading' => 'ICS-kreditkortsutdrag',
        'step_login' => 'Logga in',
        'step_download' => 'Ladda ner utdraget',
        'pdf_statement' => 'PDF-utdrag',
        'step_drop' => 'Släpp det nedan',
        'drop_zone_label' => 'Släpp din utdragsfil här',
        'drop_zone_hint' => 'eller bläddra efter en fil',
        'browse_aria' => 'Bläddra efter en ICS-utdragsfil',
        'import_button' => 'Importera utdrag',
        'validation' => [
            'required' => 'Släpp det ICS-utdrag du laddade ner från Mijn ICS.',
            'max' => 'Filen är för stor. ICS PDF-utdrag är normalt under 1 MB styck.',
            'extensions' => 'Det där är ingen PDF. Mijn ICS exporterar bara utdrag som PDF.',
        ],
        'could_not_read' => 'Kunde inte läsa :filename. Hela felet finns i /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Innan du ansluter en tredje part',
        'body' => 'När du aktiverar open banking skickas ditt samtycke till bankinloggningen, och därefter dina transaktions- och saldouppgifter, direkt från den här enheten till Enable Banking och din bank. Beatrax driver ingen server som ser de här uppgifterna — men det gör Enable Banking och din bank. Det skiljer sig från alla andra importsätt i Beatrax, som aldrig skickar data någonstans.',
        'acknowledge' => 'Jag förstår att mina transaktionsuppgifter delas med Enable Banking och min bank.',
        'confirm' => 'Aktivera open banking',
        'cancel' => 'Avbryt',
    ],

    'wizard' => [
        'heading' => 'Anslut din bank',
        'intro' => 'Beatrax använder din egen Enable Banking-applikation, så att dina uppgifter aldrig passerar en delad server. Det här är en engångskonfiguration per bank.',

        'step1_title' => 'Skapa ditt lokala nyckelpar',
        'step1_body' => 'Beatrax skapar ett RSA-nyckelpar på den här enheten. Den privata nyckeln lämnar den aldrig.',
        'generate_keypair' => 'Skapa nyckelpar',
        'public_key_label' => 'Publik nyckel',
        'copy_public_key' => 'Kopiera publik nyckel',
        'copied' => 'Kopierat',
        'redirect_uri_label' => 'Omdirigerings-URI',
        'copy_redirect_uri' => 'Kopiera omdirigerings-URI',

        'step2_title' => 'Registrera applikationen i Enable Banking',
        'step2_body' => 'Öppna utvecklarportalen för Enable Banking, skapa en applikation och klistra in den publika nyckeln och omdirigerings-URI från steg 1.',
        'open_portal' => 'Öppna Enable Banking-portalen ↗',

        'step3_title' => 'Klistra in ditt applikations-ID',
        'application_id_label' => 'Applikations-ID',
        'step3_help' => 'Detta sparas i en lokal fil utanför databasen med restriktiva behörigheter och lämnar aldrig den här enheten.',

        'step4_title' => 'Välj din bank',
        'via_enable_banking' => 'via Enable Banking',
        'other_institution' => 'Annat institut',
        'institution_id_placeholder' => 'Institutions-id',

        'step5_title' => 'Slutför samtycket i din webbläsare',
        'step5_body' => 'Klicka nedan för att öppna bankens inloggnings- och samtyckessida. Slutför inloggningen och eventuell tvåfaktorsverifiering, så förs du automatiskt tillbaka hit för att slutföra aktiveringen av Open Banking.',

        'cancel' => 'Avbryt',
        'continue' => 'Fortsätt →',
        'continue_to_bank' => 'Fortsätt till :bank →',
        'your_bank' => 'din bank',

        'errors' => [
            'save_keypair_failed' => 'Kunde inte spara ditt nyckelpar på disk — kontrollera behörigheterna för katalogen med hemligheter och försök igen.',
            'generate_failed' => 'Kunde inte skapa ett nyckelpar på den här enheten — kontrollera din OpenSSL-konfiguration.',
            'export_failed' => 'Kunde inte exportera det skapade nyckelparet.',
            'read_public_failed' => 'Kunde inte läsa den skapade publika nyckeln.',
            'generate_first' => 'Skapa ett nyckelpar innan du fortsätter.',
            'paste_application_id' => 'Klistra in applikations-ID från Enable Banking-portalen innan du fortsätter.',
            'save_application_id_failed' => 'Kunde inte spara ditt applikations-ID på disk — kontrollera behörigheterna för katalogen med hemligheter och försök igen.',
            'choose_bank' => 'Välj en bank innan du fortsätter.',
        ],
    ],

    'alert' => [
        'reconsent' => 'Anslut din bank på nytt',
    ],

    'errors' => [
        'wizard_incomplete' => 'Slutför konfigurationsguiden för Open Banking först.',
        'no_bank_chosen' => 'Välj en bank innan du ansluter.',
        'no_consent_url' => 'Enable Banking returnerade ingen samtyckes-URL.',
        'unparseable_consent_url' => 'Enable Banking returnerade en samtyckes-URL som inte gick att tolka.',
        'non_public_consent_host' => 'Enable Banking returnerade en samtyckesvärd som inte är publik.',
        'unsafe_consent_url' => 'Enable Banking returnerade en osäker samtyckes-URL.',
        'no_authorization_code' => 'Återanropet från Enable Banking innehöll ingen auktoriseringskod.',
        'no_session_id' => 'Enable Banking returnerade inget sessions-id.',
        'oauth_state_mismatch' => 'Den här anslutningslänken har gått ut eller är redan använd. Börja om med att ansluta din bank.',
    ],
];
