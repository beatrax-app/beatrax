<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Cel: :name',
        'category_goal' => 'Cel dla :name',
        'schedule_untitled' => 'Zaplanowana transakcja bez nazwy',
        'transaction' => 'Transakcja: :name · :date · :amount',
        'transaction_unnamed' => 'Transakcja',
        'amount_update' => 'Aktualizacja kwoty transakcji',
        'budget_history' => 'Historia budżetu w :currency',
        'budget_file_currency' => 'Waluta pliku budżetu',
        'budget_file_mode' => 'Tryb pliku budżetu',
    ],

    'conflict' => [
        'budget_assignment' => 'Przypisanie budżetu',
        'budget_for_month' => 'Budżet: :category · :month',
        'budget_for_category' => 'Budżet: :category',
        'category_name' => 'Nazwa kategorii',
        'category_name_of' => 'Nazwa kategorii „:name”',
        'account_name' => 'Nazwa konta',
        'account_name_of' => 'Nazwa konta „:name”',
        'transaction_amount' => 'Kwota transakcji',
        'transaction_amount_of' => 'Kwota: :name',
        'transaction_amount_of_dated' => 'Kwota: :name · :date',
        'transaction_description' => 'Opis transakcji',
        'transaction_description_of' => 'Opis: :name',
        'transaction_description_of_dated' => 'Opis: :name · :date',
        'other' => 'Zaimportowana wartość',
    ],

    'reason' => [
        'fingerprint_collision' => 'Ta transakcja pokryła się z inną, już zapisaną transakcją (identyczny odcisk) i nie została zaimportowana.',
        'split_legs_without_category' => ':count pozycja podziału z :legs nie ma kategorii, a pozycji podziału nie da się zapisać bez kategorii. Transakcja została zaimportowana w pełnej kwocie i czeka w kategorii „:uncategorized”.|:count pozycje podziału z :legs nie mają kategorii, a pozycji podziału nie da się zapisać bez kategorii. Transakcja została zaimportowana w pełnej kwocie i czeka w kategorii „:uncategorized”.|:count pozycji podziału z :legs nie ma kategorii, a pozycji podziału nie da się zapisać bez kategorii. Transakcja została zaimportowana w pełnej kwocie i czeka w kategorii „:uncategorized”.',
        'split_sum_mismatch' => 'Pozycje podziału sumują się do :legs, a transakcja wynosi :total, podczas gdy podział musi dokładnie odpowiadać swojej transakcji. Transakcja została zaimportowana w pełnej kwocie, bez pozycji.',
        'split_unstorable' => 'Beatrax nie może zapisać tego podziału w takiej postaci, więc transakcja została zaimportowana sama, bez pozycji.',
        'goal_without_target_date' => 'Ten cel nie ma daty docelowej; Beatrax jej wymaga, aby utworzyć cel oszczędnościowy.',
        'goal_without_name' => 'Ten cel nie ma nazwy; Beatrax jej wymaga, aby utworzyć cel oszczędnościowy.',
        'goal_def_unsupported' => 'categories.goal_def używa nieobsługiwanego (niepłaskiego) kształtu szablonu — cel nie został zaimportowany.',
        'budget_currency_mismatch' => ':count wiersz budżetu nie został zaimportowany: Twoje budżety są prowadzone w :envelope, a ten eksport prowadzi budżet w :source.|:count wiersze budżetu nie zostały zaimportowane: Twoje budżety są prowadzone w :envelope, a ten eksport prowadzi budżet w :source.|:count wierszy budżetu nie zostało zaimportowanych: Twoje budżety są prowadzone w :envelope, a ten eksport prowadzi budżet w :source.',
        'amount_apply_collision' => 'Nowej kwoty ze źródła nie udało się zastosować — koliduje z odciskiem innej transakcji (to samo konto, data, waluta i kontrahent). Pozostawiono bez zmian.',
        'amount_currency_mismatch' => 'Kwoty transakcji nie zostały uzgodnione: te transakcje są prowadzone w :local, a ten eksport podaje je w :source. Pozostawiono bez zmian.',
        'schedule_unsupported' => 'Beatrax nie potrafi jeszcze tworzyć transakcji zaplanowanych ani cyklicznych ze źródła zewnętrznego — zachowane tylko jako notatka, nie jako aktywna seria w sekcji Cykliczne.',
        'saved_report_unsupported' => 'Zapisane raporty i konfiguracje analiz nie mają odpowiednika w Beatrax.',
        'assumed_currency' => "Przyjęto: :currency — w tym eksporcie nie znaleziono wiersza 'preferences.currencyCode'.",
        'assumed_budget_type' => "Przyjęto: :mode — w tym eksporcie nie znaleziono wiersza 'preferences.budgetType'.",
        'changed_on_both_sides' => "Od ostatniego importu zmienił to zarówno plik źródłowy, jak i Beatrax.\nLokalnie: :local\nŹródło: :source\nOstatnio zaimportowane: :baseline",
        'take_source' => 'Wartość z nowego eksportu zostanie zastosowana, gdy potwierdzisz — Twoja lokalna wartość zostanie zastąpiona.',
        'keep_local' => 'Twoja lokalna wartość zostanie zachowana — wartość z nowego eksportu nie zostanie zastosowana.',
        'compared_values' => ":intro\nLokalnie: :local · Źródło: :source · Ostatnio zaimportowane: :baseline",
    ],

    'value' => [
        'none' => '(brak)',
        'quoted' => '„:value”',
    ],
];
