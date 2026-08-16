<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliase',
    'heading' => 'Aliase',
    'subtitle' => 'Verständliche Namen, die du Beatrax für die kryptischen Beschreibungen auf deinen Kontoauszügen beigebracht hast. Bearbeite das verallgemeinerte Muster einer Zeile, um zu erweitern oder einzugrenzen, welche anderen Transaktionen denselben verständlichen Namen übernehmen.',
    'dismiss' => 'schließen',

    'selected_count' => ':count ausgewählt',
    'merge_selected' => 'Ausgewählte zusammenführen',

    'empty_heading' => 'Noch keine Aliase',
    'empty_body' => 'Aliase erscheinen hier, nachdem du auf die kursive Rohbeschreibung einer Zeile in der Importvorschau geklickt und ihr einen verständlichen Namen gegeben hast.',

    'col_select' => 'Auswählen',
    'col_raw' => 'Rohbeschreibung',
    'col_generalized' => 'Verallgemeinertes Muster',
    'col_friendly' => 'Verständlicher Name',
    'col_actions' => 'Aktionen',

    'select_alias_aria' => 'Alias :name auswählen',
    'generalized_pattern_aria' => 'Verallgemeinertes Muster',

    'save' => 'Speichern',
    'cancel' => 'Abbrechen',
    'edit' => 'Bearbeiten',
    'delete' => 'Löschen',
    'delete_confirm' => "Diesen Alias löschen? Künftige Importe von ':pattern' fallen auf die Rohbeschreibung zurück.",

    'backup_transfer' => 'Backup & Übertragung',
    'export_yaml' => 'Aliase als YAML exportieren',

    'export_help_html' => 'Lädt <code class="font-mono">aliases.yaml</code> im Community-Corpus-Format herunter.',
    'import_from_yaml' => 'Aus YAML importieren',
    'parse_preview' => 'Einlesen & Vorschau',
    'cancel_import' => 'Import abbrechen',

    'diff_new' => 'neu,',
    'diff_unchanged' => 'unverändert,',
    'diff_conflicts' => 'Konflikte.',

    'conflicts_heading' => 'Konflikte',
    'conflict_name' => 'Name — vorhanden: :existing → Datei: :file',
    'conflict_pattern_existing' => 'Muster — vorhanden:',
    'conflict_file' => '→ Datei:',
    'resolution_for_aria' => 'Konfliktlösung für :pattern',
    'keep_yours' => 'Deine behalten',
    'replace' => 'Ersetzen',
    'confirm_import' => 'Import bestätigen',

    'preview_aria' => 'Vorschau anhand von Transaktionen',
    'test_heading' => 'An meinen Transaktionen testen',
    'test_help' => 'Bearbeite das verallgemeinerte Muster einer Zeile, um zu sehen, welche Transaktionen es treffen würde.',
    'typing' => 'Eingabe…',
    'matches_prefix' => 'Trifft auf',
    'matches_suffix' => 'Transaktionen in deinem jüngeren Verlauf zu.',

    'merge_modal_title' => ':count Aliase zusammenführen',

    'merge_modal_help_html' => 'Die verbleibende Zeile behält ihre Rohbeschreibung; die aufgenommenen Zeilen bleiben in <code class="font-mono text-xs">merged_from</code> erhalten.',
    'friendly_name_label' => 'Verständlicher Name',
    'generalized_pattern_label' => 'Verallgemeinertes Muster',
    'no_prefix_warning' => 'Über die ausgewählten Aliase hinweg wurde kein gemeinsames Präfix aus 4 Zeichen gefunden — gib vor dem Bestätigen manuell ein Muster ein.',
    'confirm_merge' => 'Zusammenführen bestätigen',

    'flash' => [
        'updated' => 'Alias aktualisiert.',
        'deleted' => 'Alias gelöscht.',
        'merged' => 'Aliase zusammengeführt.',
        'imported' => ':count Aliase importiert.',
        'nothing' => 'Nichts zu importieren.',
    ],

    'errors' => [
        'not_found' => 'Alias nicht gefunden (er wurde möglicherweise in einem anderen Tab gelöscht).',
        'pattern_empty' => 'Das verallgemeinerte Muster darf nicht leer sein.',
        'select_two' => 'Wähle mindestens zwei Aliase zum Zusammenführen aus.',
        'some_not_found' => 'Einer oder mehrere ausgewählte Aliase wurden nicht gefunden.',
        'both_required' => 'Verständlicher Name und verallgemeinertes Muster sind beide erforderlich.',
        'merge_not_found' => 'Einer oder mehrere Aliase wurden nicht gefunden (sie wurden möglicherweise in einem anderen Tab gelöscht).',
        'merge_failed' => 'Zusammenführen fehlgeschlagen (:class).',
        'no_file' => 'Keine Datei hochgeladen.',
        'unreadable' => 'Die hochgeladene Datei konnte nicht gelesen werden.',
        'too_short' => 'Das Muster ist zu kurz zum Testen.',
    ],
];
