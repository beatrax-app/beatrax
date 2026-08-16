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

    'diff_new' => 'nya,',
    'diff_unchanged' => 'oförändrade,',
    'diff_conflicts' => 'konflikter.',

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
    'matches_prefix' => 'Träffar',
    'matches_suffix' => 'transaktioner i din senaste historik.',

    'merge_modal_title' => 'Slå samman :count alias',

    'merge_modal_help_html' => 'Den kvarvarande raden behåller sin råa beskrivning; de uppslukade raderna bevaras i <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Begripligt namn',
    'generalized_pattern_label' => 'Generaliserat mönster',
    'no_prefix_warning' => 'Inget gemensamt prefix på 4 tecken hittades bland de valda aliasen — skriv in ett mönster manuellt innan du bekräftar.',
    'confirm_merge' => 'Bekräfta sammanslagningen',

    'flash' => [
        'updated' => 'Aliaset uppdaterat.',
        'deleted' => 'Aliaset borttaget.',
        'merged' => 'Aliasen sammanslagna.',
        'imported' => ':count alias importerade.',
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
    ],
];
