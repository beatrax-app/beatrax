<?php

declare(strict_types=1);

return [
    'page_title' => 'Alternatyvūs pavadinimai',
    'heading' => 'Alternatyvūs pavadinimai',
    'subtitle' => 'Suprantami pavadinimai, kuriuos išmokei Beatrax priskirti nesuprantamiems išrašų aprašymams. Redaguok eilutės apibendrintą šabloną, kad išplėstum arba susiaurintum, kurios kitos operacijos paveldi tą patį suprantamą pavadinimą.',
    'dismiss' => 'slėpti',

    'selected_count' => 'Pažymėta: :count',
    'merge_selected' => 'Sujungti pažymėtus',

    'empty_heading' => 'Alternatyvių pavadinimų dar nėra',
    'empty_body' => 'Alternatyvūs pavadinimai atsiranda čia, kai importo peržiūros eilutėje spusteli pasvirąjį pirminį aprašymą ir suteiki jam suprantamą pavadinimą.',

    'col_select' => 'Pasirinkti',
    'col_raw' => 'Pirminis aprašymas',
    'col_generalized' => 'Apibendrintas šablonas',
    'col_friendly' => 'Suprantamas pavadinimas',
    'col_actions' => 'Veiksmai',

    'select_alias_aria' => 'Pasirinkti alternatyvų pavadinimą :name',
    'generalized_pattern_aria' => 'Apibendrintas šablonas',

    'save' => 'Išsaugoti',
    'cancel' => 'Atšaukti',
    'edit' => 'Redaguoti',
    'delete' => 'Ištrinti',
    'delete_confirm' => 'Ištrinti šį alternatyvų pavadinimą? Ateityje importuojant „:pattern“ vėl bus rodomas pirminis aprašymas.',

    'backup_transfer' => 'Atsarginė kopija ir perkėlimas',
    'export_yaml' => 'Eksportuoti alternatyvius pavadinimus į YAML',

    'export_help_html' => 'Atsiunčia <code class="font-mono">aliases.yaml</code> bendruomenės korpuso formatu.',
    'import_from_yaml' => 'Importuoti iš YAML',
    'parse_preview' => 'Nuskaityti ir peržiūrėti',
    'cancel_import' => 'Atšaukti importą',

    'diff_summary' => ':new, :unchanged, :conflicts.',
    'diff_new' => ':count naujas|:count nauji|:count naujų',
    'diff_unchanged' => ':count nepakitęs|:count nepakitę|:count nepakitusių',
    'diff_conflicts' => ':count konfliktas|:count konfliktai|:count konfliktų',

    'conflicts_heading' => 'Konfliktai',
    'conflict_name' => 'pavadinimas — esamas: :existing → faile: :file',
    'conflict_pattern_existing' => 'šablonas — esamas:',
    'conflict_file' => '→ faile:',
    'resolution_for_aria' => 'Sprendimas šablonui :pattern',
    'keep_yours' => 'Palikti savo',
    'replace' => 'Pakeisti',
    'confirm_import' => 'Patvirtinti importą',

    'preview_aria' => 'Peržiūrėti pagal operacijas',
    'test_heading' => 'Išbandyti su mano operacijomis',
    'test_help' => 'Redaguok eilutės apibendrintą šabloną, kad pamatytum, kurias operacijas jis atitiktų.',
    'typing' => 'Rašoma…',
    'matches' => 'Atitinka :count operaciją tavo naujausioje istorijoje.|Atitinka :count operacijas tavo naujausioje istorijoje.|Atitinka :count operacijų tavo naujausioje istorijoje.',

    'merge_modal_title' => 'Sujungti :count alternatyvų pavadinimą|Sujungti :count alternatyvius pavadinimus|Sujungti :count alternatyvių pavadinimų',

    'merge_modal_help_html' => 'Likusi eilutė išlaiko savo pirminį aprašymą; sujungtos eilutės išsaugomos lauke <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Suprantamas pavadinimas',
    'generalized_pattern_label' => 'Apibendrintas šablonas',
    'no_prefix_warning' => 'Tarp pasirinktų alternatyvių pavadinimų nerasta bendro 4 simbolių prefikso — prieš patvirtindamas įvesk šabloną rankiniu būdu.',
    'confirm_merge' => 'Patvirtinti sujungimą',

    'flash' => [
        'updated' => 'Alternatyvus pavadinimas atnaujintas.',
        'deleted' => 'Alternatyvus pavadinimas ištrintas.',
        'merged' => 'Alternatyvūs pavadinimai sujungti.',
        'imported' => 'Importuotas :count alternatyvus pavadinimas.|Importuoti :count alternatyvūs pavadinimai.|Importuota :count alternatyvių pavadinimų.',
        'nothing' => 'Nėra ko importuoti.',
    ],

    'errors' => [
        'not_found' => 'Alternatyvus pavadinimas nerastas (galbūt jis ištrintas kitoje kortelėje).',
        'pattern_empty' => 'Apibendrintas šablonas negali būti tuščias.',
        'select_two' => 'Pasirink bent du alternatyvius pavadinimus sujungimui.',
        'some_not_found' => 'Vienas ar daugiau pasirinktų alternatyvių pavadinimų nerasta.',
        'both_required' => 'Būtini ir suprantamas pavadinimas, ir apibendrintas šablonas.',
        'merge_not_found' => 'Vienas ar daugiau alternatyvių pavadinimų nerasta (galbūt jie ištrinti kitoje kortelėje).',
        'merge_failed' => 'Sujungti nepavyko (:class).',
        'no_file' => 'Failas neįkeltas.',
        'unreadable' => 'Nepavyko perskaityti įkelto failo.',
        'too_short' => 'Šablonas per trumpas, kad būtų galima išbandyti.',
        'file_not_yaml' => 'Šis failas nėra tinkamas YAML, todėl iš jo nepavyko nieko perskaityti. Iš naujo eksportuok savo alternatyvius pavadinimus ir įkelk gautą failą.',
        'file_unreadable_as_yaml' => 'Šio failo nepavyko perskaityti kaip alternatyvių pavadinimų sąrašo. Iš naujo eksportuok savo alternatyvius pavadinimus ir įkelk gautą failą.',
        'file_has_no_entries_list' => 'Šis failas neprasideda aukščiausio lygio entries: sąrašu, todėl jame nėra ką importuoti. Patikrink, ar pasirinkai tinkamą failą.',
        'entry_is_not_a_mapping' => 'Įrašas :entry yra paprasta reikšmė ten, kur tikimasi šablono ir pavadinimo. Įrašyk abu laukus arba pašalink jį ir įkelk failą iš naujo.',
        'entry_is_missing_a_field' => 'Įraše :entry trūksta šablono arba pavadinimo, o reikia abiejų. Užpildyk, ko trūksta, arba pašalink tą įrašą ir įkelk failą iš naujo.',
    ],
];
