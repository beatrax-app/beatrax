<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Visualizzazione',
        'money' => 'Denaro',
        'insights' => 'Analisi e avvisi',
        'security' => 'Sicurezza e dispositivi',
        'data' => 'Importazioni e dati',
        'app' => 'App',
    ],

    'title' => 'Impostazioni',
    'subtitle' => "Preferenze su come appaiono le tue finanze nell'app.",

    'appearance' => [
        'heading' => 'Aspetto',
        'theme' => 'Tema',
        'theme_light' => 'Chiaro',
        'theme_dark' => 'Scuro',
        'theme_system' => 'Sistema',
        'theme_help' => '«Sistema» segue la modalità chiara o scura del tuo sistema operativo.',
    ],

    'language' => [
        'apply' => 'Applica',
        'heading' => 'Lingua',
        'label' => 'Lingua di visualizzazione',

        'system' => 'Sistema',
        'help' => "Cambia le parole che vedi sullo schermo e il modo in cui vengono scritti gli importi. «Sistema» segue la lingua del browser o del sistema operativo, con l'inglese come impostazione predefinita.",
    ],

    'timezone' => [
        'heading' => 'Fuso orario',
        'label' => 'Fuso orario di questa installazione',
        'help' => 'Decide in che giorno cade una transazione e in quale riferimento vengono salvati gli orari. I dispositivi accoppiati condividono questa impostazione, così entrambi leggono lo stesso giorno.',
        'this_machine' => 'Questo dispositivo (:zone)',
    ],

    'sample_data' => [
        'heading' => 'Dati di esempio',
        'help' => 'Riempie questo conto con un registro inventato — conti, transazioni, budget, obiettivi e avvisi — così c’è qualcosa da guardare. Si somma a quello che c’è già e nulla appartiene a una persona reale.',
        'warning' => 'Questo scrive nel tuo registro e raggiunge i tuoi dispositivi accoppiati. Da questa schermata non si annulla.',
        'confirm' => 'Aggiungilo a questo conto',
        'cancel' => 'Annulla',
        'load' => 'Carica dati di esempio',
        'working' => 'Sto costruendo il registro di esempio. Ci vuole un momento.',
        'loaded' => 'Dati di esempio aggiunti (:count).',
    ],

    'country' => [
        'heading' => 'Paese',
        'label' => 'Il tuo Paese',
        'help' => "Determina di quale Paese l'app riconosce le regole fiscali, gli enti pubblici e le commissioni bancarie. Non cambia la lingua né il modo in cui vengono scritti gli importi.",
        'choose' => 'Scegli un paese…',
        'switch_note' => 'Il cambio aggiunge nuove categorie — le etichette esistenti non vengono mai modificate.',

        'wording_note' => 'I nomi delle categorie fiscali sono nella tua lingua; la dichiarazione dei redditi di :country usa i propri termini.',

        'countries' => [
            'at' => 'Austria',
            'be' => 'Belgio',
            'bg' => 'Bulgaria',
            'ca' => 'Canada',
            'ch' => 'Svizzera',
            'cy' => 'Cipro',
            'cz' => 'Cechia',
            'de' => 'Germania',
            'dk' => 'Danimarca',
            'ee' => 'Estonia',
            'es' => 'Spagna',
            'fi' => 'Finlandia',
            'fr' => 'Francia',
            'gb' => 'Regno Unito',
            'gr' => 'Grecia',
            'hr' => 'Croazia',
            'hu' => 'Ungheria',
            'ie' => 'Irlanda',
            'is' => 'Islanda',
            'it' => 'Italia',
            'lt' => 'Lituania',
            'lu' => 'Lussemburgo',
            'lv' => 'Lettonia',
            'mt' => 'Malta',
            'nl' => 'Paesi Bassi',
            'no' => 'Norvegia',
            'pl' => 'Polonia',
            'pt' => 'Portogallo',
            'ro' => 'Romania',
            'se' => 'Svezia',
            'si' => 'Slovenia',
            'sk' => 'Slovacchia',
            'us' => 'Stati Uniti',
        ],
    ],

    'currency_display' => [
        'heading' => 'Visualizzazione dell\'importo',
        'label' => 'Vista predefinita degli importi',
        'eur_only' => 'Importo regolato',
        'original' => 'Importo originale',
        'help' => 'Vale per la lista delle transazioni e per i totali nella dashboard. Puoi comunque cambiarla pagina per pagina, ma solo dalla lista delle transazioni.',
    ],

    'base_currency' => [
        'heading' => 'Valuta di riferimento',
        'label' => 'Valuta dei report',
        'help' => 'Tutti i totali e i riepiloghi vengono convertiti in questa valuta. Ogni conto continua comunque a mostrare accanto la propria valuta originale.',
    ],

    'exchange_rates' => [
        'heading' => 'Tassi di cambio',
        'fetch_online' => 'Scarica online i tassi correnti',
        'online_on' => 'Tassi scaricati ogni giorno dalla BCE, o da Frankfurter se la BCE non è raggiungibile. Solo ricerche di coppie di valute — nessun dato personale.',
        'last_updated' => 'Ultimo aggiornamento: :date.',
        'online_off' => 'Restano in uso i tassi già presenti, con lo snapshot incluso come riserva. Nessun dato lascia questo dispositivo.',
        'fetch_aria' => 'Scarica online i tassi di cambio correnti',
        'refreshing' => 'Aggiornamento…',
        'next_refresh' => 'Aggiornamento automatico: una volta al giorno',
        'refresh_gave_up' => 'Impossibile aggiornare i tassi. Restano in uso quelli già presenti su questo dispositivo.',
        'refresh_now' => 'Aggiorna ora',
    ],

    'period' => [
        'heading' => 'Periodo',
        'label' => 'Il periodo inizia il giorno',
        'help' => 'Numerato da 1 a 28. La maggior parte degli utenti lo lascia su 1 (mese solare). Usa 25 se lo stipendio arriva il 25 e per te «il tuo mese» inizia da lì.',

        'move_confirm' => 'Se il periodo inizia il giorno :day, tutti gli importi delle buste vengono riarchiviati e sommati a due a due dove due mesi si fondono in uno. Riportare indietro il giorno non li separa di nuovo.',
        'move_cancel' => 'Annulla',
        'move_apply' => 'Applica',
    ],

    'recurring' => [
        'heading' => 'Rilevamento delle ricorrenze',
        'window_label' => 'Finestra di rilevamento (mesi)',
        'window_help' => 'Quanti mesi di cronologia analizzare quando le transazioni vengono raggruppate in schemi ricorrenti.',
        'income_label' => 'Entrate minime (unità minori)',
        'income_help' => 'Le entrate sotto questa soglia non vengono raggruppate in automatico. Memorizzate in unità minori — :minor corrisponde a :example. Imposta 0 per disattivare la soglia.',
    ],

    'drift' => [
        'heading' => 'Avvisi di scostamento',
        'label' => 'Soglia predefinita per gli avvisi di scostamento',
        'help' => "Gli avvisi scattano quando l'ultimo importo di un addebito ricorrente si discosta dal precedente di più di questa percentuale. Le impostazioni per singola serie hanno la precedenza.",
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (predefinito)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Salva impostazioni',
    'saved' => 'Salvato.',

    'anomaly_heading' => 'Rilevamento delle anomalie',
    'notifications_heading' => 'Notifiche',

    'forecasting' => [
        'heading' => 'Previsioni',
        'intro' => 'Beatrax proietta il tuo saldo partendo dallo stato attuale dei tuoi conti. Per i conti senza saldi da estratto conto (PayPal, vecchie importazioni CSV), imposta qui il saldo iniziale in modo che le proiezioni partano da un punto noto.',
        'no_accounts' => 'Ancora nessun conto — importa un estratto conto per aggiungerne uno.',
    ],

    'auto_import' => [
        'heading' => 'Importazione automatica',
        'label' => 'Importazione automatica dalla cartella di deposito',

        'active_html' => 'La cartella di deposito è attiva. Beatrax controlla <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> ogni 5 minuti per cercare nuovi file.',
        'inactive_html' => 'Quando è attiva, Beatrax controlla <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> ogni 5 minuti per cercare file <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> e <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> e li importa con la stessa pipeline di abbinamento della procedura guidata. I file elaborati vengono spostati in <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> in modo che non vengano mai importati due volte.',
        'active_phone_html' => 'La cartella di deposito è attiva. Beatrax controlla <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> in background per cercare nuovi file. Quando parte un controllo in background lo decide il tuo telefono, quindi possono passare minuti oppure ore.',
        'inactive_phone_html' => 'Quando è attiva, Beatrax controlla <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> in background per cercare file <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> e <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> e li importa con la stessa pipeline di abbinamento della procedura guidata. Quando parte un controllo in background lo decide il tuo telefono, quindi possono passare minuti oppure ore. I file elaborati vengono spostati in <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> in modo che non vengano mai importati due volte.',
    ],

    'aliases' => [
        'heading' => 'Alias',
        'intro' => 'Rivedi e modifica i nomi leggibili che hai insegnato a Beatrax per le descrizioni criptiche degli estratti conto.',
        'manage' => 'Gestisci gli alias →',
    ],

    'tax_heading' => 'Fisco',
    'data_backup_heading' => 'Dati e backup',

    'about_updates' => [
        'heading' => 'Informazioni sugli aggiornamenti',
        'body' => "Una volta installato, Beatrax si aggiorna da solo. Dopo aver installato la primissima versione, le versioni successive arrivano tramite un banner nell'app — non devi tornare su GitHub. Se in futuro un aggiornamento non dovesse riuscire, puoi sempre riscaricare a mano l'ultimo installer dalla pagina delle release.",
        'body_phone' => 'Qui Beatrax non si aggiorna da solo. Le nuove versioni dell\'app per telefono arrivano dall\'App Store o da Google Play, come le tue altre app.',
        'check_label' => 'Cerca aggiornamenti automaticamente',
        'check_on' => 'Beatrax chiede al feed delle release se esiste una versione firmata più recente. Non viene scaricato nulla finché non scegli tu di installarla.',
        'check_off' => 'Non viene fatto alcun controllo degli aggiornamenti e nulla lascia questo dispositivo. Le nuove versioni si trovano aprendo tu stesso la pagina delle release.',
        'open_releases' => 'Apri la pagina delle release →',
        'channel_label' => 'Canale di aggiornamento',
        'channel_help' => 'Stabile è l\'impostazione predefinita e propone versioni riviste da una persona. Anteprima propone le versioni candidate appena vengono pubblicate.',
        'channel_stable' => 'Stabile',
        'channel_preview' => 'Anteprima',
        'channel_preview_note' => 'Le versioni di anteprima sono testate meno di quelle stabili e possono contenere lavoro incompiuto. Prima di installare qualsiasi cosa vengono verificate con la stessa firma dell\'editore.',
    ],

    'privacy' => [
        'heading' => 'Informativa sulla privacy',
        'body' => 'Beatrax tiene le tue finanze sui tuoi dispositivi. L’informativa spiega che cosa significa, che cosa inviano le funzioni online facoltative e come rimuovere i tuoi dati.',
        'open' => 'Leggi l’informativa sulla privacy →',
        'url_hint' => 'Se il link non si apre, vai su:',
    ],

    'first_run_tour' => [
        'heading' => 'Tour iniziale',
        'body' => 'Riavvia la procedura guidata se vuoi ripercorrere il flusso introduttivo.',
        'run_again' => 'Riavvia la procedura guidata',
    ],

    'developer' => [
        'heading' => 'Sviluppatore',
        'label' => 'Dev Console integrata',
        'help' => "Mostra la Dev Console su /dev. Reimposta l'interruttore Advanced a ogni accesso.",
        'aria' => 'Modalità sviluppatore',
    ],

    'errors' => [
        'period_move_failed' => 'Non è stato possibile spostare il mese di budget, quindi è rimasto dov’era.',
        'currency_required' => 'Scegli una valuta.',
        'window_months' => 'Scegli un valore tra 2 e 60 mesi.',
        'threshold' => 'Scegli una soglia tra 1%, 2%, 5%, 10%, 25% o 50%.',
        'amount' => 'Inserisci un importo da :zero in su.',
        'period_day' => 'Scegli un giorno da 1 a 28.',
        'currency_view' => 'Scegli una delle opzioni disponibili.',
        'timezone' => 'Scegli un fuso orario dall’elenco.',
    ],
];
