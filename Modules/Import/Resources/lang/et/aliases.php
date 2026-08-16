<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliased',
    'heading' => 'Aliased',
    'subtitle' => 'Arusaadavad nimed, mille oled Beatraxile oma väljavõtete krüptiliste kirjelduste jaoks õpetanud. Muuda rea üldistatud mustrit, et laiendada või kitsendada seda, millised teised tehingud sama nime pärivad.',
    'dismiss' => 'peida',

    'selected_count' => ':count valitud',
    'merge_selected' => 'Ühenda valitud',

    'empty_heading' => 'Aliaseid veel pole',
    'empty_body' => 'Aliased ilmuvad siia pärast seda, kui klõpsad impordi eelvaate real kaldkirjas toorkirjeldusel ja annad sellele arusaadava nime.',

    'col_select' => 'Vali',
    'col_raw' => 'Toorkirjeldus',
    'col_generalized' => 'Üldistatud muster',
    'col_friendly' => 'Arusaadav nimi',
    'col_actions' => 'Toimingud',

    'select_alias_aria' => 'Vali alias :name',
    'generalized_pattern_aria' => 'Üldistatud muster',

    'save' => 'Salvesta',
    'cancel' => 'Tühista',
    'edit' => 'Muuda',
    'delete' => 'Kustuta',
    'delete_confirm' => 'Kas kustutada see alias? Edaspidistes importides läheb „:pattern“ tagasi toorkirjelduse peale.',

    'backup_transfer' => 'Varundus ja ülekanne',
    'export_yaml' => 'Ekspordi aliased YAML-ina',

    'export_help_html' => 'Laadib alla <code class="font-mono">aliases.yaml</code> kogukonna korpuse vormingus.',
    'import_from_yaml' => 'Impordi YAML-ist',
    'parse_preview' => 'Töötle ja eelvaata',
    'cancel_import' => 'Tühista import',

    'diff_new' => 'uut,',
    'diff_unchanged' => 'muutmata,',
    'diff_conflicts' => 'vastuolu.',

    'conflicts_heading' => 'Vastuolud',
    'conflict_name' => 'nimi — olemasolev: :existing → fail: :file',
    'conflict_pattern_existing' => 'muster — olemasolev:',
    'conflict_file' => '→ fail:',
    'resolution_for_aria' => 'Lahendus mustrile :pattern',
    'keep_yours' => 'Jäta enda oma',
    'replace' => 'Asenda',
    'confirm_import' => 'Kinnita import',

    'preview_aria' => 'Eelvaade tehingute vastu',
    'test_heading' => 'Testi oma tehingute vastu',
    'test_help' => 'Muuda rea üldistatud mustrit, et näha, millised tehingud sellega sobiksid.',
    'typing' => 'Tipin…',
    'matches_prefix' => 'Sobib',
    'matches_suffix' => 'tehinguga sinu hiljutises ajaloos.',

    'merge_modal_title' => 'Ühenda :count aliast',

    'merge_modal_help_html' => 'Alles jääv rida säilitab oma toorkirjelduse; ülevõetud read säilitatakse väljal <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Arusaadav nimi',
    'generalized_pattern_label' => 'Üldistatud muster',
    'no_prefix_warning' => 'Valitud aliastel ei leitud ühist neljamärgilist eesliidet — sisesta enne kinnitamist muster käsitsi.',
    'confirm_merge' => 'Kinnita ühendamine',

    'flash' => [
        'updated' => 'Alias on uuendatud.',
        'deleted' => 'Alias on kustutatud.',
        'merged' => 'Aliased on ühendatud.',
        'imported' => 'Imporditud :count aliast.',
        'nothing' => 'Pole midagi importida.',
    ],

    'errors' => [
        'not_found' => 'Aliast ei leitud (see võidi kustutada teisel vahelehel).',
        'pattern_empty' => 'Üldistatud muster ei saa olla tühi.',
        'select_two' => 'Ühendamiseks vali vähemalt kaks aliast.',
        'some_not_found' => 'Üht või mitut valitud aliast ei leitud.',
        'both_required' => 'Nii arusaadav nimi kui ka üldistatud muster on kohustuslikud.',
        'merge_not_found' => 'Üht või mitut aliast ei leitud (need võidi kustutada teisel vahelehel).',
        'merge_failed' => 'Ühendamine ebaõnnestus (:class).',
        'no_file' => 'Ühtegi faili ei laaditud üles.',
        'unreadable' => 'Üleslaaditud faili ei õnnestunud lugeda.',
        'too_short' => 'Muster on testimiseks liiga lühike.',
    ],
];
