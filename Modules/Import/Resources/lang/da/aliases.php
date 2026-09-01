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
    // i18n-review: da · empty_body_touch — the same line for a touch
    // screen; check the verb governs this case.
    'empty_body_touch' => 'Aliasser vises her, når du trykker på den kursiverede rå beskrivelse på en række i importforhåndsvisningen og giver den et forståeligt navn.',

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

    'diff_summary' => ':new, :unchanged, :conflicts.',
    // i18n-review: da · diff_new, diff_unchanged — the elided noun is alias, taken
    // here as neuter (et alias), which is what gives nyt and uændret. If Danish
    // readers say en alias, both singular arms want ny and uændret instead.
    'diff_new' => ':count nyt|:count nye',
    'diff_unchanged' => ':count uændret|:count uændrede',
    'diff_conflicts' => ':count konflikt|:count konflikter',

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
    'matches' => 'Rammer :count transaktion i din seneste historik.|Rammer :count transaktioner i din seneste historik.',

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
        'file_not_yaml' => 'Denne fil er ikke gyldig YAML, så intet i den kunne læses. Eksportér dine aliasser igen, og upload filen du får.',
        'file_unreadable_as_yaml' => 'Denne fil kunne ikke læses som en aliasliste. Eksportér dine aliasser igen, og upload filen du får.',
        'file_has_no_entries_list' => 'Denne fil begynder ikke med en entries:-liste på øverste niveau, så den indeholder ingen aliasser at importere. Tjek at du valgte den rigtige fil.',
        'entry_is_not_a_mapping' => 'Post :entry er en enkelt værdi, hvor et mønster og et navn var forventet. Giv den begge felter, eller fjern den, og upload filen igen.',
        'entry_is_missing_a_field' => 'Post :entry mangler sit mønster eller sit navn, og et alias skal have begge. Udfyld det der mangler, eller fjern posten, og upload filen igen.',
    ],
];
