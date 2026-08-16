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

    'diff_new' => 'jauni,',
    'diff_unchanged' => 'nemainīti,',
    'diff_conflicts' => 'konflikti.',

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
    'matches_prefix' => 'Atbilst',
    'matches_suffix' => 'darījumiem jūsu nesenajā vēsturē.',

    'merge_modal_title' => 'Aizstājvārdu apvienošana: :count',

    'merge_modal_help_html' => 'Atlikušajā rindā paliek tās sākotnējais apraksts; uzņemtās rindas tiek saglabātas laukā <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Saprotamais nosaukums',
    'generalized_pattern_label' => 'Vispārinātais šablons',
    'no_prefix_warning' => 'Atlasītajiem aizstājvārdiem netika atrasts kopīgs 4 rakstzīmju prefikss — pirms apstiprināšanas ievadiet šablonu manuāli.',
    'confirm_merge' => 'Apstiprināt apvienošanu',

    'flash' => [
        'updated' => 'Aizstājvārds atjaunināts.',
        'deleted' => 'Aizstājvārds dzēsts.',
        'merged' => 'Aizstājvārdi apvienoti.',
        'imported' => 'Importēti aizstājvārdi: :count.',
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
    ],
];
