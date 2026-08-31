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
    // i18n-review: de · empty_body_touch — the same line for a touch
    // screen; check the verb governs this case.
    'empty_body_touch' => 'Aliase erscheinen hier, nachdem du auf die kursive Rohbeschreibung einer Zeile in der Importvorschau getippt und ihr einen verständlichen Namen gegeben hast.',

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

    'diff_summary' => ':new, :unchanged, :conflicts.',
    // i18n-review: de · diff_new, diff_unchanged — attributive endings for an
    // elided neuter Alias, following the anomaly tile's 2 große. Both the gender
    // and the register against a bare neu are open.
    'diff_new' => ':count neues|:count neue',
    'diff_unchanged' => ':count unverändertes|:count unveränderte',
    'diff_conflicts' => ':count Konflikt|:count Konflikte',

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
    'matches' => 'Trifft auf :count Transaktion in deinem jüngeren Verlauf zu.|Trifft auf :count Transaktionen in deinem jüngeren Verlauf zu.',

    'merge_modal_title' => ':count Alias zusammenführen|:count Aliase zusammenführen',

    'merge_modal_help_html' => 'Die verbleibende Zeile behält ihre Rohbeschreibung; die aufgenommenen Zeilen bleiben in <code class="font-mono text-xs">merged_from</code> erhalten.',
    'friendly_name_label' => 'Verständlicher Name',
    'generalized_pattern_label' => 'Verallgemeinertes Muster',
    'no_prefix_warning' => 'Über die ausgewählten Aliase hinweg wurde kein gemeinsames Präfix aus 4 Zeichen gefunden — gib vor dem Bestätigen manuell ein Muster ein.',
    'confirm_merge' => 'Zusammenführen bestätigen',

    'flash' => [
        'updated' => 'Alias aktualisiert.',
        'deleted' => 'Alias gelöscht.',
        'merged' => 'Aliase zusammengeführt.',
        'imported' => ':count Alias importiert.|:count Aliase importiert.',
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
        'file_not_yaml' => 'Diese Datei ist kein gültiges YAML, deshalb konnte nichts daraus gelesen werden. Exportiere deine Aliase erneut und lade die erzeugte Datei hoch.',
        'file_unreadable_as_yaml' => 'Diese Datei ließ sich nicht als Aliasliste lesen. Exportiere deine Aliase erneut und lade die erzeugte Datei hoch.',
        'file_has_no_entries_list' => 'Diese Datei beginnt nicht mit einer entries:-Liste auf oberster Ebene, deshalb sind keine Aliase darin zu importieren. Prüfe, ob du die richtige Datei gewählt hast.',
        'entry_is_not_a_mapping' => 'Eintrag :entry ist ein einfacher Wert, wo ein Muster und ein Name erwartet wurden. Gib ihm beide Felder oder entferne ihn und lade die Datei erneut hoch.',
        'entry_is_missing_a_field' => 'Bei Eintrag :entry fehlt das Muster oder der Name, und ein Alias braucht beides. Ergänze das Fehlende oder entferne den Eintrag und lade die Datei erneut hoch.',
    ],
];
