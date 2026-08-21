<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliasser',
    'heading' => 'Aliasser',
    'subtitle' => 'Forståelige navne, som du har lært Beatrax for de kryptiske beskrivelser på dine kontoudtog. Redigér en rækkes generaliserede mønster for at udvide eller indsnævre, hvilke andre transaktioner der arver det samme forståelige navn.',
    'dismiss' => 'luk',

    'selected_count' => ':count valgt',
    'merge_selected' => 'Flet valgte',

    'empty_heading' => 'Ingen aliasser endnu',
    'empty_body' => 'Aliasser vises her, når du klikker på den kursiverede rå beskrivelse på en række i importforhåndsvisningen og giver den et forståeligt navn.',

    'col_select' => 'Vælg',
    'col_raw' => 'Rå beskrivelse',
    'col_generalized' => 'Generaliseret mønster',
    'col_friendly' => 'Forståeligt navn',
    'col_actions' => 'Handlinger',

    'select_alias_aria' => 'Vælg aliasset :name',
    'generalized_pattern_aria' => 'Generaliseret mønster',

    'save' => 'Gem',
    'cancel' => 'Annullér',
    'edit' => 'Redigér',
    'delete' => 'Slet',
    'delete_confirm' => "Slet dette alias? Fremtidige importer af ':pattern' falder tilbage til den rå beskrivelse.",

    'backup_transfer' => 'Sikkerhedskopi og overførsel',
    'export_yaml' => 'Eksportér aliasser som YAML',

    'export_help_html' => 'Henter <code class="font-mono">aliases.yaml</code> i community-korpusformatet.',
    'import_from_yaml' => 'Importér fra YAML',
    'parse_preview' => 'Indlæs og forhåndsvis',
    'cancel_import' => 'Annullér importen',

    'diff_new' => 'nye,',
    'diff_unchanged' => 'uændrede,',
    'diff_conflicts' => 'konflikter.',

    'conflicts_heading' => 'Konflikter',
    'conflict_name' => 'navn — eksisterende: :existing → fil: :file',
    'conflict_pattern_existing' => 'mønster — eksisterende:',
    'conflict_file' => '→ fil:',
    'resolution_for_aria' => 'Løsning for :pattern',
    'keep_yours' => 'Behold dine',
    'replace' => 'Erstat',
    'confirm_import' => 'Bekræft importen',

    'preview_aria' => 'Forhåndsvis mod transaktioner',
    'test_heading' => 'Test mod mine transaktioner',
    'test_help' => 'Redigér en rækkes generaliserede mønster for at se, hvilke transaktioner det ville ramme.',
    'typing' => 'Skriver…',
    'matches_prefix' => 'Rammer',
    'matches_suffix' => 'transaktioner i din seneste historik.',

    'merge_modal_title' => 'Flet :count alias|Flet :count aliasser',

    'merge_modal_help_html' => 'Den tilbageværende række beholder sin rå beskrivelse; de optagne rækker bevares i <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Forståeligt navn',
    'generalized_pattern_label' => 'Generaliseret mønster',
    'no_prefix_warning' => 'Der blev ikke fundet et fælles præfiks på 4 tegn på tværs af de valgte aliasser — indtast et mønster manuelt, før du bekræfter.',
    'confirm_merge' => 'Bekræft fletningen',

    'flash' => [
        'updated' => 'Aliasset er opdateret.',
        'deleted' => 'Aliasset er slettet.',
        'merged' => 'Aliasserne er flettet.',
        'imported' => ':count alias er importeret.|:count aliasser er importeret.',
        'nothing' => 'Intet at importere.',
    ],

    'errors' => [
        'not_found' => 'Aliasset blev ikke fundet (det kan være slettet i en anden fane).',
        'pattern_empty' => 'Det generaliserede mønster må ikke være tomt.',
        'select_two' => 'Vælg mindst to aliasser at flette.',
        'some_not_found' => 'Et eller flere valgte aliasser blev ikke fundet.',
        'both_required' => 'Både forståeligt navn og generaliseret mønster er påkrævet.',
        'merge_not_found' => 'Et eller flere aliasser blev ikke fundet (de kan være slettet i en anden fane).',
        'merge_failed' => 'Fletningen mislykkedes (:class).',
        'no_file' => 'Der er ikke uploadet nogen fil.',
        'unreadable' => 'Den uploadede fil kunne ikke læses.',
        'too_short' => 'Mønsteret er for kort til at teste.',
    ],
];
