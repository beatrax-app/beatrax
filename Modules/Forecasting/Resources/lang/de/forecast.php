<?php

declare(strict_types=1);

return [
    'heading' => 'Prognose',
    'page_title' => 'Prognose',
    'subtitle' => 'Wohin sich dein Saldo entwickelt — über die nächsten 30 bis 365 Tage.',
    'adjust_buffers' => 'Puffer anpassen',

    'empty_heading' => 'Noch keine Prognosedaten',
    'empty_body' => 'Verbinde ein Konto oder bestätige eine wiederkehrende Reihe, um deinen voraussichtlichen Saldo für die kommenden Wochen zu sehen.',
    'empty_start' => 'Beginne damit,',
    'empty_import_link' => 'einen Kontoauszug zu importieren',
    'empty_or' => 'oder',
    'empty_recurring_link' => 'wiederkehrende Muster zu prüfen',

    'account_tablist' => 'Konto',
    'all_accounts' => 'Alle Konten',

    'horizon_label' => 'Prognosehorizont',
    'n_days' => ':days Tage',

    'view_by_funder' => 'Nach Zahler anzeigen',
    'view_by_funder_hint' => 'Fasse kettenaufgelöste Reihen auf dem Konto zusammen, das sie tatsächlich bezahlt.',

    'scenario_group' => 'Szenario',
    'baseline' => 'Basislinie',
    'scenario_word' => 'Szenario',
    'new_scenario' => '+ Neues Szenario',
    'scenario_name_placeholder' => 'Szenarioname',
    'new_scenario_aria' => 'Name des neuen Szenarios',
    'create_scenario' => 'Szenario anlegen',
    'cancel' => 'Abbrechen',

    'aggregate_subtitle' => 'Kombinierter Saldo über alle Konten, projiziert über die nächsten :days Tage.',

    'today' => 'heute',
    'on_day' => 'an Tag',

    'edit_buffer_aria' => 'Mindestpuffer für :name bearbeiten',
    'buffer_not_set' => 'Puffer: nicht gesetzt',
    'buffer_set' => 'Puffer: :amount',

    'shortfall' => 'Unterdeckung beginnt :date — :amount unter deinem Puffer von :buffer',

    'compared_against_baseline' => 'Verglichen mit der Basislinie oben',

    'scenario_editor_aria' => 'Szenario-Editor',
    'series_confidence' => 'Konfidenz der Reihe',
    'no_series_contribute' => 'Noch tragen keine Reihen zur Prognose dieses Kontos bei.',

    'net_diff' => 'Nettodifferenz',
    'net_diff_section_aria' => 'Nettodifferenz zwischen Basislinie und Szenario an den Horizonttagen 30 / 60 / 90',
    'net_diff_delta_aria' => 'Nettodifferenz an Tag :day: :value, Szenario ist :state',
    'better_than_baseline' => 'besser als die Basislinie',
    'worse_than_baseline' => 'schlechter als die Basislinie',
    'equal_to_baseline' => 'gleich der Basislinie',
    'at_day' => 'an Tag :day',

    'updating' => 'Wird aktualisiert',
    'chart_noscript' => 'Das Diagramm benötigt JavaScript. Der Bereich umfasst :days Tage.',
    'total_balance' => 'Gesamtsaldo',

    'per_month_suffix' => '/Mon.',
    'confidence_chip_aria' => ':name, Konfidenz :confidence — der Projektionsbereich beträgt :percent Prozent der Punktschätzung',

    'highlights_title' => 'Prognose-Highlights',
    'highlights_shortfall_aria' => ':count aktive Unterdeckungsfenster in den nächsten 30 Tagen',
    'dips_to' => ':name fällt auf :amount',
    'on_date_suffix' => ' am :date',
    'shortfall_window' => '1 aktives Unterdeckungsfenster|:count aktive Unterdeckungsfenster',
    'lowest_in_30' => 'Tiefstand in 30 Tagen: :amount',
    'next_ics' => 'Nächste ICS-Abrechnung: :amount am :date',
];
