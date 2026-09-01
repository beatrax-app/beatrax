<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliaser',
    'heading' => 'Aliaser',
    'subtitle' => 'Forståelige navn du har lært Beatrax for de kryptiske beskrivelsene på kontoutskriftene dine. Rediger det generaliserte mønsteret i en rad for å utvide eller snevre inn hvilke andre transaksjoner som arver det samme forståelige navnet.',
    'dismiss' => 'lukk',

    'selected_count' => ':count valgt',
    'merge_selected' => 'Slå sammen valgte',

    'empty_heading' => 'Ingen aliaser ennå',
    'empty_body' => 'Aliaser dukker opp her når du klikker på den kursiverte rå beskrivelsen på en rad i importforhåndsvisningen og gir den et forståelig navn.',
    // i18n-review: nb · empty_body_touch — the same line for a touch
    // screen; check the verb governs this case.
    'empty_body_touch' => 'Aliaser dukker opp her når du trykker på den kursiverte rå beskrivelsen på en rad i importforhåndsvisningen og gir den et forståelig navn.',

    'col_select' => 'Velg',
    'col_raw' => 'Rå beskrivelse',
    'col_generalized' => 'Generalisert mønster',
    'col_friendly' => 'Forståelig navn',
    'col_actions' => 'Handlinger',

    'select_alias_aria' => 'Velg aliaset :name',
    'generalized_pattern_aria' => 'Generalisert mønster',

    'save' => 'Lagre',
    'cancel' => 'Avbryt',
    'edit' => 'Rediger',
    'delete' => 'Slett',
    'delete_confirm' => "Slette dette aliaset? Fremtidige importer av ':pattern' faller tilbake til den rå beskrivelsen.",

    'backup_transfer' => 'Sikkerhetskopi og overføring',
    'export_yaml' => 'Eksporter aliaser som YAML',

    'export_help_html' => 'Laster ned <code class="font-mono">aliases.yaml</code> i formatet for community-korpuset.',
    'import_from_yaml' => 'Importer fra YAML',
    'parse_preview' => 'Les inn og forhåndsvis',
    'cancel_import' => 'Avbryt importen',

    'diff_summary' => ':new, :unchanged, :conflicts.',
    // i18n-review: nb · diff_new, diff_unchanged — the elided noun is alias, taken
    // here as neuter (et alias), which is what gives nytt and uendret. If en alias
    // is what Norwegian readers use, both singular arms want ny and uendret.
    'diff_new' => ':count nytt|:count nye',
    'diff_unchanged' => ':count uendret|:count uendrede',
    'diff_conflicts' => ':count konflikt|:count konflikter',

    'conflicts_heading' => 'Konflikter',
    'conflict_name' => 'navn — eksisterende: :existing → fil: :file',
    'conflict_pattern_existing' => 'mønster — eksisterende:',
    'conflict_file' => '→ fil:',
    'resolution_for_aria' => 'Løsning for :pattern',
    'keep_yours' => 'Behold dine',
    'replace' => 'Erstatt',
    'confirm_import' => 'Bekreft importen',

    'preview_aria' => 'Forhåndsvis mot transaksjoner',
    'test_heading' => 'Test mot transaksjonene mine',
    'test_help' => 'Rediger det generaliserte mønsteret i en rad for å se hvilke transaksjoner det ville treffe.',
    'typing' => 'Skriver…',
    'matches' => 'Treffer :count transaksjon i den nyeste historikken din.|Treffer :count transaksjoner i den nyeste historikken din.',

    'merge_modal_title' => 'Slå sammen :count alias|Slå sammen :count aliaser',

    'merge_modal_help_html' => 'Raden som blir igjen, beholder sin rå beskrivelse; radene som slukes, bevares i <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Forståelig navn',
    'generalized_pattern_label' => 'Generalisert mønster',
    'no_prefix_warning' => 'Det ble ikke funnet noe felles prefiks på 4 tegn på tvers av de valgte aliasene — skriv inn et mønster manuelt før du bekrefter.',
    'confirm_merge' => 'Bekreft sammenslåingen',

    'flash' => [
        'updated' => 'Aliaset er oppdatert.',
        'deleted' => 'Aliaset er slettet.',
        'merged' => 'Aliasene er slått sammen.',
        'imported' => ':count alias er importert.|:count aliaser er importert.',
        'nothing' => 'Ingenting å importere.',
    ],

    'errors' => [
        'not_found' => 'Aliaset ble ikke funnet (det kan ha blitt slettet i en annen fane).',
        'pattern_empty' => 'Det generaliserte mønsteret kan ikke være tomt.',
        'select_two' => 'Velg minst to aliaser som skal slås sammen.',
        'some_not_found' => 'Ett eller flere valgte aliaser ble ikke funnet.',
        'both_required' => 'Både forståelig navn og generalisert mønster er påkrevd.',
        'merge_not_found' => 'Ett eller flere aliaser ble ikke funnet (de kan ha blitt slettet i en annen fane).',
        'merge_failed' => 'Sammenslåingen mislyktes (:class).',
        'no_file' => 'Ingen fil er lastet opp.',
        'unreadable' => 'Klarte ikke å lese filen som ble lastet opp.',
        'too_short' => 'Mønsteret er for kort til å testes.',
        'file_not_yaml' => 'Denne filen er ikke gyldig YAML, så ingenting i den kunne leses. Eksporter aliasene dine på nytt, og last opp filen du får.',
        'file_unreadable_as_yaml' => 'Denne filen kunne ikke leses som en aliasliste. Eksporter aliasene dine på nytt, og last opp filen du får.',
        'file_has_no_entries_list' => 'Denne filen begynner ikke med en entries:-liste på øverste nivå, så den inneholder ingen aliaser å importere. Sjekk at du valgte riktig fil.',
        'entry_is_not_a_mapping' => 'Oppføring :entry er en enkeltverdi der et mønster og et navn var forventet. Gi den begge feltene, eller fjern den, og last opp filen på nytt.',
        'entry_is_missing_a_field' => 'Oppføring :entry mangler mønsteret eller navnet sitt, og et alias trenger begge. Fyll inn det som mangler, eller fjern oppføringen, og last opp filen på nytt.',
    ],
];
