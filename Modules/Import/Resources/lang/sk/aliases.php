<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliasy',
    'heading' => 'Aliasy',
    'subtitle' => 'Zrozumiteľné názvy, ktoré má Beatrax priradené k záhadným popisom na tvojich výpisoch. Uprav v riadku zovšeobecnený vzor a rozšíriš alebo zúžiš, ktoré ďalšie transakcie prevezmú ten istý zrozumiteľný názov.',
    'dismiss' => 'zamietnuť',

    'selected_count' => 'Vybrané: :count',
    'merge_selected' => 'Zlúčiť vybrané',

    'empty_heading' => 'Zatiaľ žiadne aliasy',
    'empty_body' => 'Aliasy sa tu objavia, keď v ukážke importu klikneš na kurzívou písaný pôvodný popis a dáš mu zrozumiteľný názov.',

    'col_select' => 'Výber',
    'col_raw' => 'Pôvodný popis',
    'col_generalized' => 'Zovšeobecnený vzor',
    'col_friendly' => 'Zrozumiteľný názov',
    'col_actions' => 'Akcie',

    'select_alias_aria' => 'Vybrať alias :name',
    'generalized_pattern_aria' => 'Zovšeobecnený vzor',

    'save' => 'Uložiť',
    'cancel' => 'Zrušiť',
    'edit' => 'Upraviť',
    'delete' => 'Odstrániť',
    'delete_confirm' => "Odstrániť tento alias? Ďalšie importy ':pattern' sa vrátia k pôvodnému popisu.",

    'backup_transfer' => 'Záloha a prenos',
    'export_yaml' => 'Exportovať aliasy ako YAML',

    'export_help_html' => 'Stiahne <code class="font-mono">aliases.yaml</code> vo formáte komunitného korpusu.',
    'import_from_yaml' => 'Importovať z YAML',
    'parse_preview' => 'Spracovať a zobraziť ukážku',
    'cancel_import' => 'Zrušiť import',

    'diff_new' => 'nových,',
    'diff_unchanged' => 'bez zmeny,',
    'diff_conflicts' => 'konfliktov.',

    'conflicts_heading' => 'Konflikty',
    'conflict_name' => 'názov — existujúci: :existing → súbor: :file',
    'conflict_pattern_existing' => 'vzor — existujúci:',
    'conflict_file' => '→ súbor:',
    'resolution_for_aria' => 'Riešenie pre: :pattern',
    'keep_yours' => 'Ponechať tvoje',
    'replace' => 'Nahradiť',
    'confirm_import' => 'Potvrdiť import',

    'preview_aria' => 'Ukážka na tvojich transakciách',
    'test_heading' => 'Otestovať na mojich transakciách',
    'test_help' => 'Uprav v riadku zovšeobecnený vzor a uvidíš, ktorým transakciám by zodpovedal.',
    'typing' => 'Píše sa…',
    'matches_prefix' => 'Zodpovedá',
    'matches_suffix' => 'transakciám z tvojej nedávnej histórie.',

    'merge_modal_title' => 'Zlúčenie aliasov (:count)',

    'merge_modal_help_html' => 'Zostávajúci riadok si ponechá svoj pôvodný popis; pohltené riadky sa zachovajú v <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Zrozumiteľný názov',
    'generalized_pattern_label' => 'Zovšeobecnený vzor',
    'no_prefix_warning' => 'Medzi vybranými aliasmi sa nenašla spoločná 4-znaková predpona — pred potvrdením zadaj vzor ručne.',
    'confirm_merge' => 'Potvrdiť zlúčenie',

    'flash' => [
        'updated' => 'Alias upravený.',
        'deleted' => 'Alias odstránený.',
        'merged' => 'Aliasy zlúčené.',
        'imported' => 'Naimportované aliasy: :count.',
        'nothing' => 'Nie je čo importovať.',
    ],

    'errors' => [
        'not_found' => 'Alias sa nenašiel (mohol byť odstránený na inej karte).',
        'pattern_empty' => 'Zovšeobecnený vzor nemôže byť prázdny.',
        'select_two' => 'Na zlúčenie vyber aspoň dva aliasy.',
        'some_not_found' => 'Jeden alebo viac vybraných aliasov sa nenašlo.',
        'both_required' => 'Zrozumiteľný názov aj zovšeobecnený vzor sú povinné.',
        'merge_not_found' => 'Jeden alebo viac aliasov sa nenašlo (mohli byť odstránené na inej karte).',
        'merge_failed' => 'Zlúčenie zlyhalo (:class).',
        'no_file' => 'Nenahral sa žiadny súbor.',
        'unreadable' => 'Nahraný súbor sa nepodarilo prečítať.',
        'too_short' => 'Vzor je príliš krátky na otestovanie.',
    ],
];
