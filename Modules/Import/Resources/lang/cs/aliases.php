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

    'diff_new' => 'nových,',
    'diff_unchanged' => 'beze změny,',
    'diff_conflicts' => 'konfliktů.',

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
    'matches_prefix' => 'Odpovídá',
    'matches_suffix' => 'transakcím v tvé nedávné historii.',

    'merge_modal_title' => 'Sloučení aliasů (:count)',

    'merge_modal_help_html' => 'Zbývající řádek si ponechá svůj surový popis; pohlcené řádky se zachovají v <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Srozumitelný název',
    'generalized_pattern_label' => 'Zobecněný vzor',
    'no_prefix_warning' => 'U vybraných aliasů se nenašla společná 4znaková předpona — před potvrzením zadej vzor ručně.',
    'confirm_merge' => 'Potvrdit sloučení',

    'flash' => [
        'updated' => 'Alias upraven.',
        'deleted' => 'Alias smazán.',
        'merged' => 'Aliasy sloučeny.',
        'imported' => 'Naimportované aliasy: :count.',
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
    ],
];
