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
    'n_days' => ':days giorni',

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

    'aggregate_subtitle' => 'Saldo combinato di tutti i conti, proiettato nei prossimi :days giorni.',

    'today' => 'oggi',
    'on_day' => 'al giorno',

    'edit_buffer_aria' => 'Modifica la riserva minima per :name',
    'buffer_not_set' => 'Riserva: non impostata',
    'buffer_set' => 'Riserva: :amount',

    'shortfall' => 'Lo scoperto inizia il :date — :amount sotto la tua riserva di :buffer',

    'compared_against_baseline' => 'Confrontato con il riferimento qui sopra',

    'scenario_editor_aria' => 'Editor degli scenari',
    'series_confidence' => 'Affidabilità delle serie',
    'no_series_contribute' => 'Nessuna serie contribuisce ancora alla previsione di questo conto.',

    'net_diff' => 'Differenza netta',
    'net_diff_section_aria' => 'Differenza netta tra riferimento e scenario agli orizzonti di 30 / 60 / 90 giorni',
    'net_diff_delta_aria' => 'Differenza netta al giorno :day: :value, lo scenario è :state',
    'better_than_baseline' => 'migliore del riferimento',
    'worse_than_baseline' => 'peggiore del riferimento',
    'equal_to_baseline' => 'uguale al riferimento',
    'at_day' => 'al giorno :day',

    'updating' => 'Aggiornamento',
    'chart_noscript' => "Il grafico richiede JavaScript. L'intervallo copre :days giorni.",
    'total_balance' => 'Saldo totale',

    'per_month_suffix' => '/mese',
    'confidence_chip_aria' => ":name, affidabilità :confidence — l'intervallo di proiezione è il :percent per cento della stima puntuale",

    'highlights_title' => 'Punti salienti della previsione',
    'highlights_shortfall_aria' => ':count finestre di scoperto attive nei prossimi 30 giorni',
    'dips_to' => ':name scende a :amount',
    'on_date_suffix' => ' il :date',
    'shortfall_window' => '1 finestra di scoperto attiva|:count finestre di scoperto attive',
    'lowest_in_30' => 'Minimo in 30 giorni: :amount',
    'next_ics' => 'Prossimo regolamento ICS: :amount il :date',
];
