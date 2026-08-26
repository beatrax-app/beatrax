<?php

declare(strict_types=1);

return [
    'heading' => 'Prognoza',
    'page_title' => 'Prognoza',
    'subtitle' => 'Dokąd zmierza Twoje saldo — przez najbliższe 30 do 365 dni.',
    'adjust_buffers' => 'Dostosuj bufory',

    'empty_heading' => 'Brak danych do prognozy',
    'empty_body' => 'Połącz konto lub zatwierdź serię cykliczną, aby zobaczyć prognozowane saldo na najbliższe tygodnie.',
    'empty_start' => 'Zacznij od',
    'empty_import_link' => 'zaimportowania wyciągu',
    'empty_or' => 'lub',
    'empty_recurring_link' => 'przejrzenia wzorców cyklicznych',

    'account_tablist' => 'Konto',
    'all_accounts' => 'Wszystkie konta',

    'horizon_label' => 'Horyzont prognozy',
    'n_days' => ':days dzień|:days dni|:days dni',

    'view_by_funder' => 'Pokaż według płatnika',
    'view_by_funder_hint' => 'Zwiń serie rozwiązane łańcuchowo na konto, które faktycznie za nie płaci.',

    'scenario_group' => 'Scenariusz',
    'baseline' => 'Punkt odniesienia',
    'scenario_word' => 'Scenariusz',
    'new_scenario' => '+ Nowy scenariusz',
    'scenario_name_placeholder' => 'Nazwa scenariusza',
    'new_scenario_aria' => 'Nazwa nowego scenariusza',
    'create_scenario' => 'Utwórz scenariusz',
    'cancel' => 'Anuluj',

    'aggregate_subtitle' => 'Łączne saldo wszystkich kont, prognozowane na najbliższy :days dzień.|Łączne saldo wszystkich kont, prognozowane na najbliższe :days dni.|Łączne saldo wszystkich kont, prognozowane na najbliższe :days dni.',

    'today' => 'dziś',
    'on_day' => 'w dniu',

    'edit_buffer_aria' => 'Edytuj minimalny bufor dla: :name',
    'buffer_not_set' => 'Bufor: nieustawiony',
    'buffer_set' => 'Bufor: :amount',

    'shortfall' => 'Niedobór zaczyna się :date — :amount poniżej Twojego bufora :buffer',

    'compared_against_baseline' => 'Porównanie z punktem odniesienia powyżej',

    'scenario_editor_aria' => 'Edytor scenariusza',
    'series_confidence' => 'Pewność serii',
    'no_series_contribute' => 'Żadna seria nie wpływa jeszcze na prognozę tego konta.',

    'net_diff' => 'Różnica netto',

    'net_diff_unknown' => 'Jeszcze nieobliczone dla tego horyzontu.',
    'net_diff_section_aria' => 'Różnica netto między punktem odniesienia a scenariuszem w dniach horyzontu 30 / 60 / 90',
    'net_diff_delta_aria' => 'Różnica netto w dniu :day: :value, scenariusz jest :state',
    'better_than_baseline' => 'lepszy niż punkt odniesienia',
    'worse_than_baseline' => 'gorszy niż punkt odniesienia',
    'equal_to_baseline' => 'równy punktowi odniesienia',
    'at_day' => 'w dniu :day',

    'updating' => 'Aktualizowanie',
    'chart_noscript' => 'Wykres wymaga JavaScriptu. Zakres obejmuje :days dzień.|Wykres wymaga JavaScriptu. Zakres obejmuje :days dni.|Wykres wymaga JavaScriptu. Zakres obejmuje :days dni.',
    'total_balance' => 'Saldo łączne',

    'per_month_suffix' => '/mies.',
    'confidence_chip_aria' => ':name, pewność :confidence — zakres prognozy to :percent procent estymacji punktowej',

    'highlights_title' => 'Najważniejsze z prognozy',
    'highlights_shortfall_aria' => ':count aktywne okno niedoboru w ciągu najbliższych :days dni|:count aktywne okna niedoboru w ciągu najbliższych :days dni|:count aktywnych okien niedoboru w ciągu najbliższych :days dni',
    'on_date_suffix' => ' dnia :date',
    'shortfall_window' => ':count aktywne okno niedoboru|:count aktywne okna niedoboru|:count aktywnych okien niedoboru',
    'lowest_in_30_label' => 'Najniższe saldo w ciągu 30 dni',
    'next_ics' => 'Następne rozliczenie ICS: :amount dnia :date',
    'ics_overdue' => 'Rozliczenie ICS po terminie: :amount, termin minął :date',
];
