<?php

declare(strict_types=1);

return [
    'what_heading' => 'Di cosa vuoi essere avvisato',

    'reminders' => [
        'label' => 'Promemoria di pagamento',
        'help' => 'Ricevi un avviso prima della scadenza di un pagamento ricorrente.',
    ],

    'lead_days' => [
        'label' => 'Avvisami ___ giorni prima',
        'help' => 'Quanti giorni prima della scadenza scatta il promemoria. 1–30 giorni.',
    ],

    'budget_nudges' => [
        'label' => 'Avvisi di budget',
        'help' => 'Ricevi un avviso quando il budget di una categoria è quasi esaurito.',
    ],

    'digest' => [
        'label' => 'La tua situazione settimanale',
        'help' => 'Con quale frequenza ricevi un riepilogo della situazione di questo periodo.',
        'daily' => 'Ogni giorno',
        'weekly' => 'Ogni settimana',
        'off' => 'Disattivato',
    ],

    'savings' => [
        'label' => 'Avvisi su opportunità di risparmio',
        'help' => 'Ricevi un avviso quando Beatrax individua un piano più economico o un modo per risparmiare.',
    ],

    'when_heading' => 'Quando e come',

    'quiet_hours' => [
        'label' => 'Ore di silenzio',
        'help' => 'Nessun suono né banner in questa fascia — le notifiche arrivano comunque nella tua casella.',
        'from' => 'Dalle',
        'to' => 'Alle',
    ],

    'hide_details' => [
        'label' => 'Nascondi i dettagli nelle notifiche',
        'help' => 'Mostra gli importi e i nomi degli esercenti nel banner della notifica. Disattiva se il tuo schermo potrebbe essere visibile ad altri.',
    ],

    'save' => 'Salva le impostazioni delle notifiche',
    'saved' => 'Salvato.',

    'other_devices' => [
        'summary' => 'Altri dispositivi',
        'empty' => 'Nessun altro dispositivo ancora abbinato.',
        'unnamed' => 'Dispositivo senza nome',

        'summary_line' => 'promemoria :reminders · avvisi :nudges · riepilogo :digest · risparmi :savings',
        'on' => 'attivo',
        'off' => 'disattivo',
    ],

    'errors' => [
        'save_failed' => 'Impossibile salvare le impostazioni delle notifiche. Riprova.',
    ],
];
