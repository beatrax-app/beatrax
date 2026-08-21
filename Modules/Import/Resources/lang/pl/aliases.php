<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliasy',
    'heading' => 'Aliasy',
    'subtitle' => 'Czytelne nazwy przypisane w Beatrax do zagadkowych opisów z Twoich wyciągów. Edytuj uogólniony wzorzec w wierszu, aby poszerzyć lub zawęzić to, które inne transakcje przejmą tę samą czytelną nazwę.',
    'dismiss' => 'odrzuć',

    'selected_count' => 'wybrano: :count',
    'merge_selected' => 'Scal wybrane',

    'empty_heading' => 'Brak aliasów',
    'empty_body' => 'Aliasy pojawią się tutaj, gdy klikniesz zapisany kursywą surowy opis w wierszu podglądu importu i nadasz mu czytelną nazwę.',

    'col_select' => 'Wybór',
    'col_raw' => 'Surowy opis',
    'col_generalized' => 'Uogólniony wzorzec',
    'col_friendly' => 'Czytelna nazwa',
    'col_actions' => 'Akcje',

    'select_alias_aria' => 'Wybierz alias :name',
    'generalized_pattern_aria' => 'Uogólniony wzorzec',

    'save' => 'Zapisz',
    'cancel' => 'Anuluj',
    'edit' => 'Edytuj',
    'delete' => 'Usuń',
    'delete_confirm' => "Usunąć ten alias? Przyszłe importy ':pattern' wrócą do surowego opisu.",

    'backup_transfer' => 'Kopia zapasowa i przenoszenie',
    'export_yaml' => 'Eksportuj aliasy jako YAML',

    'export_help_html' => 'Pobiera <code class="font-mono">aliases.yaml</code> w formacie korpusu społeczności.',
    'import_from_yaml' => 'Importuj z YAML',
    'parse_preview' => 'Wczytaj i pokaż podgląd',
    'cancel_import' => 'Anuluj import',

    'diff_new' => 'nowe,',
    'diff_unchanged' => 'bez zmian,',
    'diff_conflicts' => 'konflikty.',

    'conflicts_heading' => 'Konflikty',
    'conflict_name' => 'nazwa — istniejąca: :existing → plik: :file',
    'conflict_pattern_existing' => 'wzorzec — istniejący:',
    'conflict_file' => '→ plik:',
    'resolution_for_aria' => 'Rozstrzygnięcie dla: :pattern',
    'keep_yours' => 'Zachowaj swoje',
    'replace' => 'Zastąp',
    'confirm_import' => 'Potwierdź import',

    'preview_aria' => 'Podgląd na tle transakcji',
    'test_heading' => 'Przetestuj na moich transakcjach',
    'test_help' => 'Edytuj uogólniony wzorzec w wierszu, aby zobaczyć, które transakcje zostaną dopasowane.',
    'typing' => 'Pisanie…',
    'matches_prefix' => 'Pasuje do',
    'matches_suffix' => 'transakcji w Twojej ostatniej historii.',

    'merge_modal_title' => 'Scal :count alias|Scal :count aliasy|Scal :count aliasów',

    'merge_modal_help_html' => 'Pozostający wiersz zachowuje swój surowy opis; wchłonięte wiersze zostają zapisane w <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Czytelna nazwa',
    'generalized_pattern_label' => 'Uogólniony wzorzec',
    'no_prefix_warning' => 'Wśród wybranych aliasów nie znaleziono wspólnego 4-znakowego prefiksu — wpisz wzorzec ręcznie przed potwierdzeniem.',
    'confirm_merge' => 'Potwierdź scalanie',

    'flash' => [
        'updated' => 'Alias zaktualizowany.',
        'deleted' => 'Alias usunięty.',
        'merged' => 'Aliasy scalone.',
        'imported' => 'Zaimportowano :count alias.|Zaimportowano :count aliasy.|Zaimportowano :count aliasów.',
        'nothing' => 'Nie ma czego importować.',
    ],

    'errors' => [
        'not_found' => 'Nie znaleziono aliasu (mógł zostać usunięty w innej karcie).',
        'pattern_empty' => 'Uogólniony wzorzec nie może być pusty.',
        'select_two' => 'Wybierz co najmniej dwa aliasy do scalenia.',
        'some_not_found' => 'Nie znaleziono co najmniej jednego z wybranych aliasów.',
        'both_required' => 'Czytelna nazwa i uogólniony wzorzec są wymagane.',
        'merge_not_found' => 'Nie znaleziono co najmniej jednego aliasu (mógł zostać usunięty w innej karcie).',
        'merge_failed' => 'Scalanie nie powiodło się (:class).',
        'no_file' => 'Nie wgrano pliku.',
        'unreadable' => 'Nie udało się odczytać wgranego pliku.',
        'too_short' => 'Wzorzec jest za krótki, aby go przetestować.',
    ],
];
