<?php

declare(strict_types=1);

return [
    'tables' => 'Tabele',
    'schema_viewer_aria' => 'Podgląd schematu',
    'columns' => 'kolumn',
    'indexes' => 'indeksów',
    'foreign_keys' => 'kluczy obcych',
    'browse' => 'Przeglądaj',
    'heading' => 'SQL',

    'subtitle_html' => 'Panel zapytań wyłącznie typu SELECT. Walidator (na etapie parsowania) oraz PRAGMA <code class="font-mono text-xs">query_only = 1</code> (na etapie silnika) odrzucają każde zapytanie inne niż SELECT. Twardy limit 5 sekund czasu rzeczywistego.',
    'advanced_off_strong' => 'Tryb zaawansowany jest WYŁĄCZONY.',
    'advanced_off_hint' => 'Włącz tryb zaawansowany (Dev Mode → Advanced), aby uruchamiać zapytania.',
    'statement_label' => 'Zapytanie SELECT',
    'run' => 'Uruchom',
    'rows_meta' => ':rows wierszy · :durationms',
    'no_rows' => 'Zapytanie nie zwróciło żadnych wierszy.',

    'errors' => [
        'advanced_off' => 'Włącz tryb zaawansowany (Dev Mode → Advanced), aby uruchamiać zapytania.',
        'only_select' => 'Dozwolone są wyłącznie zapytania SELECT. Powód odrzucenia: :reason.',
        'timeout' => 'Zapytanie przekroczyło limit 5 sekund. Dopracuj zapytanie i spróbuj ponownie.',
        'engine' => 'Błąd SQL: :message',
        'unknown_table' => 'Nieznana tabela.',
    ],
];
