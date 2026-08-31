<?php

declare(strict_types=1);

return [
    'page_title' => 'Aizstājvārdi',
    'heading' => 'Aizstājvārdi',
    'subtitle' => 'Saprotami nosaukumi, ko esat iemācījuši Beatrax neskaidrajiem apzīmējumiem savos konta izrakstos. Rediģējiet rindas vispārināto šablonu, lai paplašinātu vai sašaurinātu to, kuri citi darījumi manto to pašu saprotamo nosaukumu.',
    'dismiss' => 'aizvērt',

    'selected_count' => 'atlasīti: :count',
    'merge_selected' => 'Apvienot atlasītos',

    'empty_heading' => 'Vēl nav aizstājvārdu',
    'empty_body' => 'Aizstājvārdi parādās šeit pēc tam, kad importa priekšskatījuma rindā noklikšķiniet uz slīprakstā rakstītā sākotnējā apraksta un piešķiriet tam saprotamu nosaukumu.',
    // i18n-review: lv · empty_body_touch — the same line for a touch
    // screen; check the verb governs this case.
    'empty_body_touch' => 'Aizstājvārdi parādās šeit pēc tam, kad importa priekšskatījuma rindā pieskarieties slīprakstā rakstītā sākotnējā apraksta un piešķiriet tam saprotamu nosaukumu.',

    'col_select' => 'Atlasīt',
    'col_raw' => 'Sākotnējais apraksts',
    'col_generalized' => 'Vispārinātais šablons',
    'col_friendly' => 'Saprotamais nosaukums',
    'col_actions' => 'Darbības',

    'select_alias_aria' => 'Atlasīt aizstājvārdu :name',
    'generalized_pattern_aria' => 'Vispārinātais šablons',

    'save' => 'Saglabāt',
    'cancel' => 'Atcelt',
    'edit' => 'Rediģēt',
    'delete' => 'Dzēst',
    'delete_confirm' => "Dzēst šo aizstājvārdu? Turpmākajos importos ':pattern' atgriezīsies pie sākotnējā apraksta.",

    'backup_transfer' => 'Dublēšana un pārsūtīšana',
    'export_yaml' => 'Eksportēt aizstājvārdus kā YAML',

    'export_help_html' => 'Lejupielādē <code class="font-mono">aliases.yaml</code> kopienas korpusa formātā.',
    'import_from_yaml' => 'Importēt no YAML',
    'parse_preview' => 'Nolasīt un priekšskatīt',
    'cancel_import' => 'Atcelt importu',

    'diff_summary' => ':new, :unchanged, :conflicts.',
    // i18n-review: lv · diff_new, diff_unchanged, diff_conflicts — Latvian selects
    // arm 0 for zero, so the genitive plural leads and the singular follows. That
    // arm does render here: a parsed file can bring nothing new.
    'diff_new' => ':count jaunu|:count jauns|:count jauni',
    'diff_unchanged' => ':count nemainītu|:count nemainīts|:count nemainīti',
    'diff_conflicts' => ':count konfliktu|:count konflikts|:count konflikti',

    'conflicts_heading' => 'Konflikti',
    'conflict_name' => 'nosaukums — esošais: :existing → failā: :file',
    'conflict_pattern_existing' => 'šablons — esošais:',
    'conflict_file' => '→ failā:',
    'resolution_for_aria' => 'Risinājums šablonam :pattern',
    'keep_yours' => 'Paturēt savējo',
    'replace' => 'Aizstāt',
    'confirm_import' => 'Apstiprināt importu',

    'preview_aria' => 'Priekšskatīt salīdzinājumā ar darījumiem',
    'test_heading' => 'Pārbaudīt ar maniem darījumiem',
    'test_help' => 'Rediģējiet rindas vispārināto šablonu, lai redzētu, kuriem darījumiem tas atbilstu.',
    'typing' => 'Raksta…',
    'matches' => 'Atbilst :count darījumiem jūsu nesenajā vēsturē.|Atbilst :count darījumam jūsu nesenajā vēsturē.|Atbilst :count darījumiem jūsu nesenajā vēsturē.',

    'merge_modal_title' => 'Apvienot :count aizstājvārdu|Apvienot :count aizstājvārdu|Apvienot :count aizstājvārdus',

    'merge_modal_help_html' => 'Atlikušajā rindā paliek tās sākotnējais apraksts; uzņemtās rindas tiek saglabātas laukā <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Saprotamais nosaukums',
    'generalized_pattern_label' => 'Vispārinātais šablons',
    'no_prefix_warning' => 'Atlasītajiem aizstājvārdiem netika atrasts kopīgs 4 rakstzīmju prefikss — pirms apstiprināšanas ievadiet šablonu manuāli.',
    'confirm_merge' => 'Apstiprināt apvienošanu',

    'flash' => [
        'updated' => 'Aizstājvārds atjaunināts.',
        'deleted' => 'Aizstājvārds dzēsts.',
        'merged' => 'Aizstājvārdi apvienoti.',
        'imported' => 'Importēti :count aizstājvārdu.|Importēts :count aizstājvārds.|Importēti :count aizstājvārdi.',
        'nothing' => 'Nav ko importēt.',
    ],

    'errors' => [
        'not_found' => 'Aizstājvārds nav atrasts (iespējams, tas ir dzēsts citā cilnē).',
        'pattern_empty' => 'Vispārinātais šablons nedrīkst būt tukšs.',
        'select_two' => 'Apvienošanai atlasiet vismaz divus aizstājvārdus.',
        'some_not_found' => 'Viens vai vairāki atlasītie aizstājvārdi netika atrasti.',
        'both_required' => 'Gan saprotamais nosaukums, gan vispārinātais šablons ir obligāti.',
        'merge_not_found' => 'Viens vai vairāki aizstājvārdi netika atrasti (iespējams, tie ir dzēsti citā cilnē).',
        'merge_failed' => 'Apvienošana neizdevās (:class).',
        'no_file' => 'Nav augšupielādēts neviens fails.',
        'unreadable' => 'Neizdevās nolasīt augšupielādēto failu.',
        'too_short' => 'Šablons ir pārāk īss, lai to pārbaudītu.',
        'file_not_yaml' => 'Šis fails nav derīgs YAML, tāpēc no tā neizdevās nolasīt neko. Eksportējiet savus aizstājvārdus vēlreiz un augšupielādējiet iegūto failu.',
        'file_unreadable_as_yaml' => 'Šo failu neizdevās nolasīt kā aizstājvārdu sarakstu. Eksportējiet savus aizstājvārdus vēlreiz un augšupielādējiet iegūto failu.',
        'file_has_no_entries_list' => 'Šis fails nesākas ar augšējā līmeņa entries: sarakstu, tāpēc tajā nav aizstājvārdu, ko importēt. Pārbaudiet, vai izvēlējāties pareizo failu.',
        'entry_is_not_a_mapping' => 'Ieraksts :entry ir vienkārša vērtība tur, kur tika gaidīts šablons un nosaukums. Pievienojiet tam abus laukus vai noņemiet to un augšupielādējiet failu vēlreiz.',
        'entry_is_missing_a_field' => 'Ierakstam :entry trūkst šablona vai nosaukuma, bet aizstājvārdam vajadzīgi abi. Aizpildiet trūkstošo vai noņemiet šo ierakstu un augšupielādējiet failu vēlreiz.',
    ],
];
