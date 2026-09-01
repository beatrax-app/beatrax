<?php

declare(strict_types=1);

return [
    'page_title' => 'Alias',
    'heading' => 'Alias',
    'subtitle' => 'Begripliga namn som du har lärt Beatrax för de kryptiska beskrivningarna på dina kontoutdrag. Redigera en rads generaliserade mönster för att bredda eller begränsa vilka andra transaktioner som ärver samma begripliga namn.',
    'dismiss' => 'stäng',

    'selected_count' => ':count valda',
    'merge_selected' => 'Slå samman valda',

    'empty_heading' => 'Inga alias ännu',
    'empty_body' => 'Alias visas här när du klickar på den kursiva råa beskrivningen på en rad i importförhandsgranskningen och ger den ett begripligt namn.',
    // i18n-review: sv · empty_body_touch — the same line for a touch
    // screen; check the verb governs this case.
    'empty_body_touch' => 'Alias visas här när du trycker på den kursiva råa beskrivningen på en rad i importförhandsgranskningen och ger den ett begripligt namn.',

    'col_select' => 'Välj',
    'col_raw' => 'Rå beskrivning',
    'col_generalized' => 'Generaliserat mönster',
    'col_friendly' => 'Begripligt namn',
    'col_actions' => 'Åtgärder',

    'select_alias_aria' => 'Välj aliaset :name',
    'generalized_pattern_aria' => 'Generaliserat mönster',

    'save' => 'Spara',
    'cancel' => 'Avbryt',
    'edit' => 'Redigera',
    'delete' => 'Ta bort',
    'delete_confirm' => "Ta bort det här aliaset? Framtida importer av ':pattern' återgår till den råa beskrivningen.",

    'backup_transfer' => 'Säkerhetskopiering och överföring',
    'export_yaml' => 'Exportera alias som YAML',

    'export_help_html' => 'Laddar ner <code class="font-mono">aliases.yaml</code> i formatet för community-korpusen.',
    'import_from_yaml' => 'Importera från YAML',
    'parse_preview' => 'Läs in och förhandsgranska',
    'cancel_import' => 'Avbryt importen',

    'diff_summary' => ':new, :unchanged, :conflicts.',
    // i18n-review: sv · diff_new, diff_unchanged — the elided noun is alias, taken
    // here as neuter (ett alias), which is what gives nytt and oförändrat. A
    // common-gender alias would want ny and oförändrad instead.
    'diff_new' => ':count nytt|:count nya',
    'diff_unchanged' => ':count oförändrat|:count oförändrade',
    'diff_conflicts' => ':count konflikt|:count konflikter',

    'conflicts_heading' => 'Konflikter',
    'conflict_name' => 'namn — befintligt: :existing → fil: :file',
    'conflict_pattern_existing' => 'mönster — befintligt:',
    'conflict_file' => '→ fil:',
    'resolution_for_aria' => 'Lösning för :pattern',
    'keep_yours' => 'Behåll dina',
    'replace' => 'Ersätt',
    'confirm_import' => 'Bekräfta importen',

    'preview_aria' => 'Förhandsgranska mot transaktioner',
    'test_heading' => 'Testa mot mina transaktioner',
    'test_help' => 'Redigera en rads generaliserade mönster för att se vilka transaktioner det skulle träffa.',
    'typing' => 'Skriver…',
    'matches' => 'Träffar :count transaktion i din senaste historik.|Träffar :count transaktioner i din senaste historik.',

    'merge_modal_title' => 'Slå samman :count alias|Slå samman :count alias',

    'merge_modal_help_html' => 'Den kvarvarande raden behåller sin råa beskrivning; de uppslukade raderna bevaras i <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Begripligt namn',
    'generalized_pattern_label' => 'Generaliserat mönster',
    'no_prefix_warning' => 'Inget gemensamt prefix på 4 tecken hittades bland de valda aliasen — skriv in ett mönster manuellt innan du bekräftar.',
    'confirm_merge' => 'Bekräfta sammanslagningen',

    'flash' => [
        'updated' => 'Aliaset uppdaterat.',
        'deleted' => 'Aliaset borttaget.',
        'merged' => 'Aliasen sammanslagna.',
        'imported' => ':count alias importerades.|:count alias importerades.',
        'nothing' => 'Inget att importera.',
    ],

    'errors' => [
        'not_found' => 'Aliaset hittades inte (det kan ha tagits bort i en annan flik).',
        'pattern_empty' => 'Det generaliserade mönstret får inte vara tomt.',
        'select_two' => 'Välj minst två alias att slå samman.',
        'some_not_found' => 'Ett eller flera valda alias hittades inte.',
        'both_required' => 'Både begripligt namn och generaliserat mönster krävs.',
        'merge_not_found' => 'Ett eller flera alias hittades inte (de kan ha tagits bort i en annan flik).',
        'merge_failed' => 'Sammanslagningen misslyckades (:class).',
        'no_file' => 'Ingen fil uppladdad.',
        'unreadable' => 'Det gick inte att läsa den uppladdade filen.',
        'too_short' => 'Mönstret är för kort för att testa.',
        'file_not_yaml' => 'Den här filen är inte giltig YAML, så inget i den gick att läsa. Exportera dina alias igen och ladda upp filen du får.',
        'file_unreadable_as_yaml' => 'Den här filen gick inte att läsa som en aliaslista. Exportera dina alias igen och ladda upp filen du får.',
        'file_has_no_entries_list' => 'Den här filen börjar inte med en entries:-lista på översta nivån, så den innehåller inga alias att importera. Kontrollera att du valde rätt fil.',
        'entry_is_not_a_mapping' => 'Post :entry är ett ensamt värde där ett mönster och ett namn förväntades. Ge den båda fälten, eller ta bort den, och ladda upp filen igen.',
        'entry_is_missing_a_field' => 'Post :entry saknar sitt mönster eller sitt namn, och ett alias behöver båda. Fyll i det som saknas, eller ta bort posten, och ladda upp filen igen.',
    ],
];
