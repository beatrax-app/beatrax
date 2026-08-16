<?php

declare(strict_types=1);

return [
    'page_title' => 'Álnevek',
    'heading' => 'Álnevek',
    'subtitle' => 'Beszédes nevek, amelyeket megtanítottál a Beatraxnak a számlakivonataidon szereplő rejtélyes leírásokhoz. Szerkeszd egy sor általánosított mintáját, hogy bővítsd vagy szűkítsd, mely további tranzakciók öröklik ugyanazt a beszédes nevet.',
    'dismiss' => 'elvetés',

    'selected_count' => ':count kijelölve',
    'merge_selected' => 'Kijelöltek egyesítése',

    'empty_heading' => 'Még nincsenek álnevek',
    'empty_body' => 'Az álnevek akkor jelennek meg itt, ha az import előnézetében rákattintasz egy sor dőlt betűs nyers leírására, és beszédes nevet adsz neki.',

    'col_select' => 'Kijelölés',
    'col_raw' => 'Nyers leírás',
    'col_generalized' => 'Általánosított minta',
    'col_friendly' => 'Beszédes név',
    'col_actions' => 'Műveletek',

    'select_alias_aria' => ':name álnév kijelölése',
    'generalized_pattern_aria' => 'Általánosított minta',

    'save' => 'Mentés',
    'cancel' => 'Mégse',
    'edit' => 'Szerkesztés',
    'delete' => 'Törlés',
    'delete_confirm' => "Törlöd ezt az álnevet? A(z) ':pattern' jövőbeli importjai visszatérnek a nyers leíráshoz.",

    'backup_transfer' => 'Mentés és átvitel',
    'export_yaml' => 'Álnevek exportálása YAML-be',

    'export_help_html' => 'Letölti az <code class="font-mono">aliases.yaml</code> fájlt a közösségi korpusz formátumában.',
    'import_from_yaml' => 'Import YAML-ből',
    'parse_preview' => 'Beolvasás és előnézet',
    'cancel_import' => 'Import megszakítása',

    'diff_new' => 'új,',
    'diff_unchanged' => 'változatlan,',
    'diff_conflicts' => 'ütközés.',

    'conflicts_heading' => 'Ütközések',
    'conflict_name' => 'név — meglévő: :existing → fájl: :file',
    'conflict_pattern_existing' => 'minta — meglévő:',
    'conflict_file' => '→ fájl:',
    'resolution_for_aria' => 'Feloldás ehhez: :pattern',
    'keep_yours' => 'A tiéd marad',
    'replace' => 'Csere',
    'confirm_import' => 'Import megerősítése',

    'preview_aria' => 'Előnézet a tranzakciókon',
    'test_heading' => 'Tesztelés a saját tranzakcióimon',
    'test_help' => 'Szerkeszd egy sor általánosított mintáját, hogy lásd, mely tranzakciókra illeszkedne.',
    'typing' => 'Gépelés…',
    'matches_prefix' => 'Illeszkedik',
    'matches_suffix' => 'tranzakcióra a legutóbbi előzményedben.',

    'merge_modal_title' => ':count álnév egyesítése',

    'merge_modal_help_html' => 'A megmaradó sor megtartja a nyers leírását; a beolvasztott sorok a <code class="font-mono text-xs">merged_from</code> mezőben maradnak meg.',
    'friendly_name_label' => 'Beszédes név',
    'generalized_pattern_label' => 'Általánosított minta',
    'no_prefix_warning' => 'A kijelölt álnevek között nem található közös, 4 karakteres előtag — a megerősítés előtt írj be kézzel egy mintát.',
    'confirm_merge' => 'Egyesítés megerősítése',

    'flash' => [
        'updated' => 'Álnév frissítve.',
        'deleted' => 'Álnév törölve.',
        'merged' => 'Álnevek egyesítve.',
        'imported' => ':count álnév importálva.',
        'nothing' => 'Nincs mit importálni.',
    ],

    'errors' => [
        'not_found' => 'Az álnév nem található (lehet, hogy egy másik lapon törölték).',
        'pattern_empty' => 'Az általánosított minta nem lehet üres.',
        'select_two' => 'Az egyesítéshez jelölj ki legalább két álnevet.',
        'some_not_found' => 'Egy vagy több kijelölt álnév nem található.',
        'both_required' => 'A beszédes név és az általánosított minta is kötelező.',
        'merge_not_found' => 'Egy vagy több álnév nem található (lehet, hogy egy másik lapon törölték).',
        'merge_failed' => 'Az egyesítés sikertelen (:class).',
        'no_file' => 'Nem töltöttél fel fájlt.',
        'unreadable' => 'A feltöltött fájl nem olvasható.',
        'too_short' => 'A minta túl rövid a teszteléshez.',
    ],
];
