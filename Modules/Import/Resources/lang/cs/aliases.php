<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliasy',
    'heading' => 'Aliasy',
    'subtitle' => 'Srozumitelné názvy, které jsi v Beatraxu přiřadil kryptickým popisům ze svých výpisů. Uprav v řádku zobecněný vzor, ať rozšíříš nebo zúžíš, které další transakce převezmou stejný srozumitelný název.',
    'dismiss' => 'zamítnout',

    'selected_count' => 'vybráno: :count',
    'merge_selected' => 'Sloučit vybrané',

    'empty_heading' => 'Zatím žádné aliasy',
    'empty_body' => 'Aliasy se tu objeví, jakmile v řádku náhledu importu klikneš na kurzívou psaný surový popis a dáš mu srozumitelný název.',
    // i18n-review: cs · empty_body_touch — the same line for a touch
    // screen; check the verb governs this case.
    'empty_body_touch' => 'Aliasy se tu objeví, jakmile v řádku náhledu importu klepneš na kurzívou psaný surový popis a dáš mu srozumitelný název.',

    'col_select' => 'Výběr',
    'col_raw' => 'Surový popis',
    'col_generalized' => 'Zobecněný vzor',
    'col_friendly' => 'Srozumitelný název',
    'col_actions' => 'Akce',

    'select_alias_aria' => 'Vybrat alias :name',
    'generalized_pattern_aria' => 'Zobecněný vzor',

    'save' => 'Uložit',
    'cancel' => 'Zrušit',
    'edit' => 'Upravit',
    'delete' => 'Smazat',
    'delete_confirm' => 'Smazat tento alias? Budoucí importy \':pattern\' se vrátí k surovému popisu.',

    'backup_transfer' => 'Záloha a přenos',
    'export_yaml' => 'Exportovat aliasy jako YAML',

    'export_help_html' => 'Stáhne <code class="font-mono">aliases.yaml</code> ve formátu komunitního korpusu.',
    'import_from_yaml' => 'Importovat z YAML',
    'parse_preview' => 'Načíst a zobrazit náhled',
    'cancel_import' => 'Zrušit import',

    'diff_summary' => ':new, :unchanged, :conflicts.',
    'diff_new' => ':count nový|:count nové|:count nových',
    'diff_unchanged' => ':count beze změny|:count beze změny|:count beze změny',
    'diff_conflicts' => ':count konflikt|:count konflikty|:count konfliktů',

    'conflicts_heading' => 'Konflikty',
    'conflict_name' => 'název — stávající: :existing → soubor: :file',
    'conflict_pattern_existing' => 'vzor — stávající:',
    'conflict_file' => '→ soubor:',
    'resolution_for_aria' => 'Řešení pro: :pattern',
    'keep_yours' => 'Ponechat své',
    'replace' => 'Nahradit',
    'confirm_import' => 'Potvrdit import',

    'preview_aria' => 'Náhled proti transakcím',
    'test_heading' => 'Vyzkoušet na mých transakcích',
    'test_help' => 'Uprav v řádku zobecněný vzor a uvidíš, které transakce by mu odpovídaly.',
    'typing' => 'Píše se…',
    'matches' => 'Odpovídá :count transakci v tvé nedávné historii.|Odpovídá :count transakcím v tvé nedávné historii.|Odpovídá :count transakcím v tvé nedávné historii.',

    'merge_modal_title' => 'Sloučit :count alias|Sloučit :count aliasy|Sloučit :count aliasů',

    'merge_modal_help_html' => 'Zbývající řádek si ponechá svůj surový popis; pohlcené řádky se zachovají v <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Srozumitelný název',
    'generalized_pattern_label' => 'Zobecněný vzor',
    'no_prefix_warning' => 'U vybraných aliasů se nenašla společná 4znaková předpona — před potvrzením zadej vzor ručně.',
    'confirm_merge' => 'Potvrdit sloučení',

    'flash' => [
        'updated' => 'Alias upraven.',
        'deleted' => 'Alias smazán.',
        'merged' => 'Aliasy sloučeny.',
        'imported' => 'Naimportován :count alias.|Naimportovány :count aliasy.|Naimportováno :count aliasů.',
        'nothing' => 'Není co importovat.',
    ],

    'errors' => [
        'not_found' => 'Alias nenalezen (mohl být smazán na jiné kartě).',
        'pattern_empty' => 'Zobecněný vzor nemůže být prázdný.',
        'select_two' => 'Ke sloučení vyber alespoň dva aliasy.',
        'some_not_found' => 'Jeden nebo více vybraných aliasů se nenašlo.',
        'both_required' => 'Srozumitelný název i zobecněný vzor jsou povinné.',
        'merge_not_found' => 'Jeden nebo více aliasů se nenašlo (mohly být smazány na jiné kartě).',
        'merge_failed' => 'Sloučení selhalo (:class).',
        'no_file' => 'Nebyl nahrán žádný soubor.',
        'unreadable' => 'Nahraný soubor se nepodařilo přečíst.',
        'too_short' => 'Vzor je příliš krátký na test.',
        'file_not_yaml' => 'Tento soubor není platný YAML, takže se z něj nedalo nic přečíst. Vyexportuj své aliasy znovu a nahraj získaný soubor.',
        'file_unreadable_as_yaml' => 'Tento soubor se nepodařilo přečíst jako seznam aliasů. Vyexportuj své aliasy znovu a nahraj získaný soubor.',
        'file_has_no_entries_list' => 'Tento soubor nezačíná seznamem entries: na nejvyšší úrovni, takže v něm nejsou žádné aliasy k importu. Zkontroluj, že jde o správný soubor.',
        'entry_is_not_a_mapping' => 'Položka :entry je prostá hodnota tam, kde se čekal vzor a název. Doplň obě pole, nebo ji odeber, a nahraj soubor znovu.',
        'entry_is_missing_a_field' => 'Položce :entry chybí vzor nebo název a alias potřebuje obojí. Doplň, co chybí, nebo tuto položku odeber, a nahraj soubor znovu.',
    ],
];
