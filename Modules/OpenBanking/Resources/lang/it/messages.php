<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Impostazioni',
        'heading' => 'Open banking',
        'subtitle' => 'Recupera automaticamente le transazioni da ASN o SNS tramite Enable Banking, un aggregatore PSD2 di terze parti. Disattivato per impostazione predefinita.',
        'toggle_label' => 'Attiva open banking',
        'toggle_connected' => 'Collegato a :bank tramite Enable Banking.',
        'toggle_off_help' => 'Disattivato per impostazione predefinita. Richiede una conferma una tantum e una configurazione guidata.',
        'reconfirm_body' => 'La tua conferma è scaduta prima che potessimo completare il collegamento. Conferma di nuovo per finire di attivare open banking.',
        'reconfirm_button' => 'Conferma di nuovo per completare',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Gestisci open banking',
        'not_connected' => 'Nessuna banca collegata. Collegane una per importare le transazioni automaticamente.',
        'expired' => 'Consenso scaduto — serve un nuovo collegamento.',
        'connected' => 'Collegato a :bank tramite Enable Banking. Ultima sincronizzazione :when.',
        'never' => 'mai',
    ],

    'transparency' => [
        'aggregator_label' => 'Aggregatore',
        'bank_label' => 'Banca',
        'consent_status_label' => 'Stato del consenso',
        'pill_expired' => 'Scaduto — ricollega',
        'pill_expiring' => 'In scadenza',
        'pill_connected' => 'Collegato',
        'whats_fetched_label' => 'Cosa viene recuperato',
        'whats_fetched' => 'Transazioni contabilizzate + saldi, ultimi 90 giorni',
        'last_successful_sync_label' => 'Ultima sincronizzazione riuscita',
        'never' => 'Mai',
        'last_attempt_label' => 'Ultimo tentativo',
        'last_attempt_failed' => ':when — non riuscito (:reason)',
        'reason_consent_expired' => 'consenso scaduto',
        'reason_error' => 'errore',
        'disconnect_button' => 'Scollega',
    ],

    'consent_banner' => [
        'heading' => 'Consenso scaduto — ricollega',
        'body' => "L'ultima sincronizzazione riuscita è stata :when. Ricollega per riprendere la sincronizzazione automatica.",
        'never' => 'mai',
        'reconnect' => 'Ricollega',
    ],

    'sync' => [
        'review_import' => "Rivedi l'importazione",
        'reconnect_first' => 'Prima ricollega',
        'auto_caption' => 'Si sincronizza automaticamente una volta al giorno.',
        'sync_now' => 'Sincronizza ora',

        'consent_expired' => 'Consenso scaduto — ricollega.',
        'unavailable' => 'Enable Banking non è disponibile al momento. Riprova tra poco.',
        'new_found' => ':count nuova transazione trovata.|:count nuove transazioni trovate.',
        'none' => 'Nessuna nuova transazione.',
    ],

    'disconnect' => [
        'heading' => 'Scollegare open banking?',
        'body' => 'Questo rimuove le credenziali Enable Banking e il consenso memorizzati. La sincronizzazione automatica si interrompe subito. Le transazioni già importate in Beatrax non vengono modificate.',
        'confirm' => 'Scollega',
        'cancel' => 'Mantieni il collegamento',
    ],

    'ics' => [
        'section_label' => 'Importazione da file — nessuna credenziale memorizzata',
        'heading' => 'Estratto conto della carta di credito ICS',
        'step_login' => 'Accedi',
        'step_download' => "Scarica l'estratto conto",
        'pdf_statement' => 'Estratto conto PDF',
        'step_drop' => 'Trascinalo qui sotto',
        'drop_zone_label' => "Trascina qui il file dell'estratto conto",
        'drop_zone_hint' => 'oppure cerca un file',
        'browse_aria' => 'Cerca un file di estratto conto ICS',
        'import_button' => 'Importa estratto conto',
        'validation' => [
            'required' => "Trascina l'estratto conto ICS che hai scaricato da Mijn ICS.",
            'max' => 'Quel file è troppo grande. Gli estratti conto PDF di ICS di solito stanno sotto 1 MB ciascuno.',
            'extensions' => 'Non è un PDF. Mijn ICS esporta solo estratti conto in PDF.',
        ],
        'could_not_read' => "Impossibile leggere :filename. L'errore completo è in /dev/logs.",
    ],

    'warning' => [
        'heading' => 'Prima di collegare una terza parte',
        'body' => 'Attivando open banking, il consenso di accesso alla tua banca e poi i dati di transazioni e saldi vengono inviati direttamente da questo dispositivo a Enable Banking e alla tua banca. Beatrax non gestisce un server che veda questi dati — ma Enable Banking e la tua banca sì. Questo è diverso da ogni altro metodo di importazione di Beatrax, che non invia mai dati da nessuna parte.',
        'acknowledge' => 'Ho capito che i miei dati di transazione saranno condivisi con Enable Banking e con la mia banca.',
        'confirm' => 'Attiva open banking',
        'cancel' => 'Annulla',
    ],

    'wizard' => [
        'heading' => 'Collega la tua banca',
        'intro' => 'Beatrax usa la tua applicazione Enable Banking, così le tue credenziali non passano mai da un server condiviso. È una configurazione una tantum per ogni banca.',

        'step1_title' => 'Genera la tua coppia di chiavi locale',
        'step1_body' => 'Beatrax genera una coppia di chiavi RSA su questo dispositivo. La chiave privata non lo lascia mai.',
        'generate_keypair' => 'Genera la coppia di chiavi',
        'public_key_label' => 'Chiave pubblica',
        'copy_public_key' => 'Copia la chiave pubblica',
        'copied' => 'Copiata',
        'redirect_uri_label' => 'URI di reindirizzamento',
        'copy_redirect_uri' => "Copia l'URI di reindirizzamento",

        'step2_title' => "Registra l'applicazione in Enable Banking",
        'step2_body' => "Apri il portale sviluppatori di Enable Banking, crea un'applicazione e incolla la chiave pubblica e l'URI di reindirizzamento del passaggio 1.",
        'open_portal' => 'Apri il portale Enable Banking ↗',

        'step3_title' => 'Incolla il tuo ID applicazione',
        'application_id_label' => 'ID applicazione',
        'step3_help' => 'Viene salvato in un file locale fuori dal database, con permessi restrittivi, e non lascia mai questo dispositivo.',

        'step4_title' => 'Scegli la tua banca',
        'via_enable_banking' => 'tramite Enable Banking',
        'other_institution' => 'Altro istituto',
        'institution_id_placeholder' => 'ID istituto',

        'step5_title' => 'Completa il consenso nel browser',
        'step5_body' => "Fai clic qui sotto per aprire la schermata di accesso e consenso della tua banca. Completa l'accesso e l'eventuale passaggio a 2 fattori, poi verrai riportato qui automaticamente per finire di attivare Open Banking.",

        'cancel' => 'Annulla',
        'continue' => 'Continua →',
        'continue_to_bank' => 'Continua su :bank →',
        'your_bank' => 'la tua banca',

        'errors' => [
            'save_keypair_failed' => 'Impossibile salvare la coppia di chiavi su disco — controlla i permessi della cartella dei secret e riprova.',
            'generate_failed' => 'Impossibile generare una coppia di chiavi su questo dispositivo — controlla la configurazione di OpenSSL.',
            'export_failed' => 'Impossibile esportare la coppia di chiavi generata.',
            'read_public_failed' => 'Impossibile leggere la chiave pubblica generata.',
            'generate_first' => 'Genera una coppia di chiavi prima di continuare.',
            'paste_application_id' => "Incolla l'ID applicazione dal portale Enable Banking prima di continuare.",
            'save_application_id_failed' => "Impossibile salvare l'ID applicazione su disco — controlla i permessi della cartella dei secret e riprova.",
            'choose_bank' => 'Scegli una banca prima di continuare.',
        ],
    ],

    'alert' => [
        'reconsent' => 'Ricollega la tua banca',
    ],

    'errors' => [
        'wizard_incomplete' => 'Completa prima la procedura guidata di Open Banking.',
        'no_bank_chosen' => 'Scegli una banca prima di collegarti.',
        'no_consent_url' => 'Enable Banking non ha restituito un URL di consenso.',
        'unparseable_consent_url' => 'Enable Banking ha restituito un URL di consenso non interpretabile.',
        'non_public_consent_host' => 'Enable Banking ha restituito un host di consenso non pubblico.',
        'unsafe_consent_url' => 'Enable Banking ha restituito un URL di consenso non sicuro.',
        'no_authorization_code' => 'Il callback di Enable Banking non ha restituito alcun codice di autorizzazione.',
        'no_session_id' => 'Enable Banking non ha restituito un id di sessione.',
    ],
];
