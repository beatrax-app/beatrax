<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Configura il tuo client OAuth Gmail',
    'microsoft_title' => 'Configura il tuo client OAuth Microsoft 365',
    'intro' => 'Beatrax usa il tuo progetto Google Cloud / la tua registrazione app Azure, così le tue credenziali non toccano mai un server condiviso. È una configurazione una tantum per ciascun provider.',

    'copied' => 'Copiato',
    'cancel' => 'Annulla',
    'save_connect' => 'Salva e collega',

    'secret_help' => 'Vengono salvate in un file di configurazione locale fuori dal database con permessi restrittivi e non lasciano mai questo dispositivo.',

    'gmail' => [
        'step1_title' => 'Apri Google Cloud Console',
        'step1_body' => "Apri Google Cloud Console in una nuova scheda. Accedi con l'account Google che vuoi scansionare, poi crea un nuovo progetto (o seleziona un progetto personale esistente).",
        'step1_link' => 'Apri Google Cloud Console',
        'step2_title' => 'Attiva la Gmail API',
        'step2_body' => 'Nel nuovo progetto, cerca "Gmail API" nella API Library e fai clic su Enable. Questo consente al progetto di chiamare Gmail per tuo conto.',
        'step3_title' => 'Configura la schermata di consenso OAuth',
        'step3_body' => 'Apri APIs & Services → OAuth consent screen. Scegli il tipo di utente "External", inserisci "Beatrax" come nome applicazione e la tua email come contatto di supporto e contatto sviluppatore. Aggiungi lo scope https://www.googleapis.com/auth/gmail.readonly. Fai clic su Save and continue, poi su Back to Dashboard.',
        'step4_title' => 'Porta la schermata di consenso su "In production"',
        'step4_body' => 'Nella pagina OAuth consent screen, fai clic su Publish App e conferma. È obbligatorio — senza questo passaggio i refresh token che Beatrax riceve scadono dopo 7 giorni. La pubblicazione non richiede alcuna revisione da parte di Google quando il solo utente sei tu.',
        'step4_checkbox' => 'Ho pubblicato la schermata di consenso OAuth su In production',
        'step5_title' => 'Crea il Client ID OAuth',
        'step5_body' => 'Apri Credentials → Create Credentials → OAuth Client ID. Scegli il tipo di applicazione "Web application". Imposta il nome "Beatrax". In "Authorized redirect URIs" incolla esattamente il seguente URI.',
        'step6_title' => 'Incolla il tuo client ID e il client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Apri il portale Azure',
        'step1_body' => "Apri il centro di amministrazione Microsoft Entra in una nuova scheda. Accedi con l'account Microsoft che vuoi scansionare.",
        'step1_link' => 'Apri il portale Azure',
        'step2_title' => 'Registra una nuova applicazione',
        'step2_body' => 'Apri App registrations → New registration. Chiamala "Beatrax". In "Supported account types" scegli "Accounts in any organizational directory and personal Microsoft accounts" (così puoi collegare caselle personali Outlook.com e aziendali Microsoft 365 con la stessa app).',
        'step3_title' => 'Aggiungi il redirect URI',
        'step3_body' => 'Nello stesso modulo di registrazione, in "Redirect URI", scegli la piattaforma "Web" e incolla esattamente il seguente URI.',
        'step4_title' => 'Concedi il permesso Mail.Read',
        'step4_body' => 'Apri API permissions → Add a permission → Microsoft Graph → Delegated permissions. Seleziona Mail.Read e offline_access. Fai clic su Add permissions. Per un account personale non serve il consenso di un amministratore.',
        'step5_title' => 'Crea un client secret',
        'step5_body' => 'Apri Certificates & secrets → New client secret. Imposta la descrizione "Beatrax" e una scadenza di 24 mesi. Copia subito il valore del secret — Azure lo mostra una sola volta.',
        'step6_title' => 'Incolla il tuo ID applicazione (client) e il secret',
        'client_id_label' => 'ID applicazione (client)',
        'client_secret_label' => 'Valore del client secret',
    ],

    'errors' => [
        'pick_provider' => 'Scegli un provider prima di inviare.',
        'microsoft_client_id' => "Inserisci l'ID applicazione (client) — un UUID come 12345678-1234-1234-1234-123456789abc.",
        'microsoft_secret' => 'Inserisci il valore del client secret che Azure ti ha mostrato quando hai creato il secret.',
        'google_client_id' => 'Inserisci un client ID OAuth Google che termina con .apps.googleusercontent.com.',
        'google_secret' => 'Inserisci un client secret OAuth Google che inizia con GOCSPX-.',
        'google_published' => "Conferma di aver portato la schermata di consenso OAuth su 'In production'.",
        'write_failed' => 'Impossibile salvare il tuo client OAuth su disco — controlla i permessi della cartella dei secret e riprova.',
    ],
];
