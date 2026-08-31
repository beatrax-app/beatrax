<?php

declare(strict_types=1);

return [
    'heading' => 'Previsione',
    'page_title' => 'Previsione',
    'subtitle' => 'Dove sta andando il tuo saldo — nei prossimi 30-365 giorni.',
    'adjust_buffers' => 'Regola le riserve',

    'empty_heading' => 'Ancora nessun dato di previsione',
    'empty_body' => 'Collega un conto o approva una serie ricorrente per vedere il saldo proiettato nelle prossime settimane.',
    'empty_start' => 'Inizia',
    'empty_import_link' => 'importando un estratto conto',
    'empty_or' => 'o',
    'empty_recurring_link' => 'rivedendo gli schemi ricorrenti',

    'account_tablist' => 'Conto',
    'all_accounts' => 'Tutti i conti',

    'horizon_label' => 'Orizzonte di previsione',
    'n_days' => ':days giorno|:days giorni',

    'view_by_funder' => 'Visualizza per finanziatore',
    'view_by_funder_hint' => 'Raggruppa le serie risolte tramite catena sul conto che le paga davvero.',

    'scenario_group' => 'Scenario',
    'baseline' => 'Riferimento',
    'scenario_word' => 'Scenario',
    'new_scenario' => '+ Nuovo scenario',
    'scenario_name_placeholder' => 'Nome dello scenario',
    'new_scenario_aria' => 'Nome del nuovo scenario',
    'create_scenario' => 'Crea scenario',
    'cancel' => 'Annulla',

    'aggregate_subtitle' => 'Saldo combinato di tutti i conti, proiettato nel prossimo :days giorno.|Saldo combinato di tutti i conti, proiettato nei prossimi :days giorni.',

    'today' => 'oggi',
    'on_day' => 'al giorno',

    'edit_buffer_aria' => 'Modifica la riserva minima per :name',
    'buffer_not_set' => 'Riserva: non impostata',
    'buffer_set' => 'Riserva: :amount',

    'shortfall' => 'Lo scoperto inizia il :date — :amount sotto la tua riserva di :buffer',

    'compared_against_baseline' => 'Confrontato con il riferimento qui sopra',

    'run_failed' => 'Non è stato possibile calcolare questa proiezione. La linea qui sotto mostra solo ciò che è già registrato.',

    'scenario_editor_aria' => 'Editor degli scenari',
    'series_confidence' => 'Affidabilità delle serie',
    'no_series_contribute' => 'Nessuna serie contribuisce ancora alla previsione di questo conto.',

    'net_diff' => 'Differenza netta',

    'net_diff_unknown' => 'Non ancora calcolato per questo orizzonte.',
    'net_diff_section_aria' => 'Differenza netta tra riferimento e scenario agli orizzonti di 30 / 60 / 90 giorni',
    'net_diff_delta_aria' => 'Differenza netta al giorno :day: :value, lo scenario è :state',
    'better_than_baseline' => 'migliore del riferimento',
    'worse_than_baseline' => 'peggiore del riferimento',
    'equal_to_baseline' => 'uguale al riferimento',
    'at_day' => 'al giorno :day',

    'updating' => 'Aggiornamento',
    'chart_noscript' => "Il grafico richiede JavaScript. L'intervallo copre :days giorno.|Il grafico richiede JavaScript. L'intervallo copre :days giorni.",
    'total_balance' => 'Saldo totale',
    'projection_range' => 'Intervallo di proiezione',
    'point_estimate' => 'Stima puntuale',

    'per_month_suffix' => '/mese',
    'confidence_chip_aria' => ":name, affidabilità :confidence — l'intervallo di proiezione è il :percent per cento della stima puntuale",

    'highlights_title' => 'Punti salienti della previsione',
    'highlights_shortfall_aria' => ':count finestra di scoperto attiva nei prossimi :days giorni|:count finestre di scoperto attive nei prossimi :days giorni',
    'on_date_suffix' => ' il :date',
    'shortfall_window' => ':count finestra di scoperto attiva|:count finestre di scoperto attive',
    'lowest_in_30_label' => 'Minimo in 30 giorni',
    'next_ics' => 'Prossimo regolamento ICS: :amount il :date',
    'ics_overdue' => 'Regolamento ICS scaduto: :amount, scaduto il :date',

    'stale_run' => 'Proiettato dal :date — non aggiornato da allora.',

    'confidence' => [
        'high' => 'Alta',
        'medium' => 'Media',
        'low' => 'Bassa',
    ],

    'errors' => [
        'amount_required' => 'L’importo è obbligatorio.',
        'amount_decimals' => 'L’importo deve essere un numero con al massimo :decimals decimale.|L’importo deve essere un numero con al massimo :decimals decimali.',
        'amount_whole' => 'L’importo deve essere un numero intero: questa valuta non ha unità minori.',
        'amount_non_negative' => 'L’importo deve essere zero o positivo.',
        'amount_non_zero' => 'L’importo non può essere zero.',
        'field_required' => 'Il campo :field è obbligatorio.',
    ],
];
