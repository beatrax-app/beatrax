<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Informazioni su :subject',
        'close' => 'Chiudi',
    ],

    'page_title' => 'Dove sono i miei dati?',
    'intro' => 'Beatrax salva tutto su questo dispositivo. Non esiste un server Beatrax né un account nel cloud. Esce solo ciò che colleghi tu — una casella di posta, una banca tramite Enable Banking, i dispositivi che associ per la sincronizzazione — più una richiesta giornaliera dei tassi di cambio. Ogni collegamento lo dice nella schermata in cui lo attivi.',

    'lives_here' => 'I tuoi dati si trovano qui',
    'copy' => 'Copia',
    'copied' => 'Copiato',

    'location' => [
        'database' => 'Database:',
        'artefacts_imports' => 'Estratti conto importati:',
        'artefacts_mail' => 'Posta analizzata:',
        'artefacts_drop' => 'Cartella sorvegliata:',
        'backups' => 'Backup:',
        'secrets' => 'Credenziali dei collegamenti:',
        'logs' => 'Log:',
    ],

    'copy_aria' => [
        'database' => 'Copia negli appunti il percorso del database',
        'artefacts_imports' => 'Copia negli appunti il percorso degli estratti conto importati',
        'artefacts_mail' => 'Copia negli appunti il percorso della posta analizzata',
        'artefacts_drop' => 'Copia negli appunti il percorso della cartella sorvegliata',
        'backups' => 'Copia negli appunti il percorso dei backup',
        'secrets' => 'Copia negli appunti il percorso delle credenziali dei collegamenti',
        'logs' => 'Copia negli appunti il percorso dei log',
    ],

    'artefacts_heading' => 'I tuoi documenti originali non sono dentro il backup',
    'artefacts_body' => "Un backup contiene il database e nient'altro. Gli estratti conto che hai importato, la posta raccolta dallo scanner e le ricevute che hai lasciato nella cartella sorvegliata restano dove sono, nelle tre cartelle elencate sopra. Mettere un backup al sicuro non le copia, quindi un archivio completo significa portarsi via anche quelle cartelle — oppure usare Esporta tutto qui sotto, che le impacchetta insieme al backup.",

    'export_heading' => 'Esporta tutto',
    'export_body' => 'Un unico archivio con una copia cifrata del tuo database e ogni documento originale che hai dato a Beatrax. Scompattalo dove vuoi e i tuoi documenti sono lì dentro come sono sempre stati, nelle cartelle da cui provengono.',
    'export_passphrase_label' => 'Passphrase per il database',
    'export_confirm_label' => 'Ripeti la passphrase',
    'export_passphrase_hint' => "Il database dentro l'archivio è cifrato con questa passphrase e senza non c'è modo di aprirlo, quindi scegline una che avrai ancora. I tuoi documenti originali entrano così come sono, perciò tieni l'archivio in un posto di cui ti fidi.",
    'export_cta' => 'Esporta tutto come ZIP',
    'export_working' => "Creazione dell'archivio…",

    'delete_heading' => 'Eliminare i tuoi dati',
    'delete_intro' => "I tuoi dati sono file su questo dispositivo, quindi eliminarli significa eliminare quei file. Qui non c'è un pulsante che lo faccia al posto tuo, ed è voluto: la tua cronologia la tiene davvero il file system, e un comando che svuotasse qualche tabella lasciando i file al loro posto sarebbe peggio di niente.",
    'delete_uninstall' => 'Disinstallare Beatrax non elimina i tuoi dati. È una scelta deliberata — una disinstallazione accidentale non deve distruggere anni di storia — quindi tutto quello che segue resta su questo dispositivo finché non lo rimuovi tu.',
    'delete_list_intro' => 'Per non lasciare tracce, elimina ognuna di queste voci:',
    'delete_journal_note' => 'Accanto al database ci sono due file di journal, :wal e :shm. Le tue modifiche più recenti stanno lì finché non vengono riversate nel database, quindi eliminali tutti e tre insieme.',
    'no_telemetry' => 'Non ci sono dati di telemetria da disattivare né account remoti da chiudere.',
];
