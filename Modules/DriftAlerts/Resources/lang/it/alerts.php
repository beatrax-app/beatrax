<?php

declare(strict_types=1);

return [
    'page_title' => 'Avvisi di scostamento',
    'heading' => 'Avvisi',
    'intro_anomaly' => 'Addebiti singoli che per te sembrano fuori dal comune.',
    'intro_drift' => 'Serie ricorrenti approvate il cui ultimo addebito è uscito dalla tua soglia.',
    'adjust_threshold' => 'Modifica la soglia →',
    'adjust_sensitivity' => 'Modifica la sensibilità →',

    'type_aria' => 'Tipo di avviso',
    'type' => [
        'drift' => 'Scostamento degli abbonamenti',
        'anomaly' => 'Addebiti insoliti',
    ],

    'lifecycle_aria' => "Ciclo di vita dell'avviso",
    'tabs' => [
        'open' => 'Aperti',
        'history' => 'Cronologia',
        'dismissed' => 'Ignorati',
    ],

    'load_more' => 'Carica altri',
    'group_count' => ':count scostamento aperto|:count scostamenti aperti',

    'anomaly_empty' => [
        'open_heading' => 'Nessun addebito insolito',
        'open_body' => 'Beatrax controlla le tue spese e segnala gli addebiti che sembrano fuori dal comune. Quando arriva qualcosa di insolito, compare qui.',
        'history_heading' => 'Ancora nessun addebito confermato',
        'history_body' => 'Gli addebiti che hai confermato compaiono qui, così puoi vedere cosa hai già rivisto.',
        'dismissed_heading' => 'Ancora niente di ignorato',
        'dismissed_body' => 'Quando contrassegni un addebito come previsto, arriva qui con la sua regola di esclusione.',
    ],

    'empty_open' => [
        'heading' => 'Nessun avviso di scostamento aperto',
        'body' => "Beatrax controlla le tue serie ricorrenti approvate e segnala quelle il cui ultimo addebito si discosta dall'importo precedente più della tua soglia. Modifica la soglia in",
        'link' => 'Impostazioni → Avviso di scostamento predefinito',
    ],
    'empty_history' => [
        'heading' => 'Ancora nessuno scostamento confermato',
        'body' => 'Gli avvisi di scostamento confermati compaiono qui, così puoi vedere cosa hai già rivisto.',
    ],
    'empty_dismissed' => [
        'heading' => 'Ancora niente di ignorato',
        'body' => 'Quando dici a Beatrax che hai disdetto una serie, quella decisione arriva qui con data e ora.',
    ],

    'row' => [
        'per_year' => '/anno',
        'meta_prior_now' => 'prima :prior → ora :now',
        'meta_detected' => 'rilevato il :date',
        'meta_threshold' => 'soglia ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/anno)',
        'cancel_impact' => 'Disdici → risparmia :amount/anno',
        'cadence_flipped' => 'Cadenza cambiata — visibile anche in',
        'cadence_flipped_link' => 'Rivedi le ricorrenti',
        'acknowledge' => 'Conferma',
        'acknowledge_aria' => "Conferma l'avviso di scostamento :id",
        'snooze' => 'Posticipa ▾',
        'snooze_1w' => '1 settimana',
        'snooze_1m' => '1 mese',
        'snooze_3m' => '3 mesi',
        'model_cancel' => 'Simula la disdetta ↗',
        'model_cancel_aria' => "Simula la disdetta — simula la disdetta nella previsione per l'avviso di scostamento :id",
        'cancelled' => "L'ho disdetto",
        'cancelled_aria' => "L'ho disdetto — ignora l'avviso di scostamento :id come disdetto",
    ],

    'toasts' => [
        'acknowledged' => 'Confermato',
        'snoozed' => 'Posticipato',
        'dismissed' => 'Ignorato',
        'suppression_added' => 'Regola di esclusione aggiunta — Annulla',
        'dismissed_expected' => 'Ignorato come previsto',
        'reopened' => 'Riaperto',
        'dismissed_cancelled' => 'Ignorato come disdetto',
    ],
];
