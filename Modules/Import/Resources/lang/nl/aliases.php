<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliassen',
    'heading' => 'Aliassen',
    'subtitle' => 'Herkenbare namen die je Beatrax hebt geleerd voor de cryptische omschrijvingen op je afschriften. Bewerk het gegeneraliseerde patroon van een rij om te verbreden of te versmallen welke andere transacties dezelfde herkenbare naam overnemen.',
    'dismiss' => 'sluiten',

    'selected_count' => ':count geselecteerd',
    'merge_selected' => 'Geselecteerde samenvoegen',

    'empty_heading' => 'Nog geen aliassen',
    'empty_body' => 'Aliassen verschijnen hier nadat je op de cursieve ruwe omschrijving van een importvoorvertoning-rij klikt en er een herkenbare naam aan geeft.',

    'col_select' => 'Selecteren',
    'col_raw' => 'Ruwe omschrijving',
    'col_generalized' => 'Gegeneraliseerd patroon',
    'col_friendly' => 'Herkenbare naam',
    'col_actions' => 'Acties',

    'select_alias_aria' => 'Alias :name selecteren',
    'generalized_pattern_aria' => 'Gegeneraliseerd patroon',

    'save' => 'Opslaan',
    'cancel' => 'Annuleren',
    'edit' => 'Bewerken',
    'delete' => 'Verwijderen',
    'delete_confirm' => "Deze alias verwijderen? Toekomstige imports van ':pattern' vallen terug op de ruwe omschrijving.",

    'backup_transfer' => 'Back-up & overdracht',
    'export_yaml' => 'Aliassen exporteren als YAML',
    'export_help_html' => 'Downloadt <code class="font-mono">aliases.yaml</code> in het community-corpus-formaat.',
    'import_from_yaml' => 'Importeren uit YAML',
    'parse_preview' => 'Inlezen & voorvertonen',
    'cancel_import' => 'Import annuleren',

    'diff_new' => 'nieuw,',
    'diff_unchanged' => 'ongewijzigd,',
    'diff_conflicts' => 'conflicten.',

    'conflicts_heading' => 'Conflicten',
    'conflict_name' => 'naam — bestaand: :existing → bestand: :file',
    'conflict_pattern_existing' => 'patroon — bestaand:',
    'conflict_file' => '→ bestand:',
    'resolution_for_aria' => 'Oplossing voor :pattern',
    'keep_yours' => 'De jouwe behouden',
    'replace' => 'Vervangen',
    'confirm_import' => 'Import bevestigen',

    'preview_aria' => 'Voorvertonen tegen transacties',
    'test_heading' => 'Testen tegen mijn transacties',
    'test_help' => 'Bewerk het gegeneraliseerde patroon van een rij om te zien welke transacties het zou matchen.',
    'typing' => 'Bezig met typen…',
    'matches_prefix' => 'Matcht',
    'matches_suffix' => 'transacties in je recente geschiedenis.',

    'merge_modal_title' => ':count alias samenvoegen|:count aliassen samenvoegen',
    'merge_modal_help_html' => 'De overblijvende rij behoudt zijn ruwe omschrijving; de opgenomen rijen worden bewaard in <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Herkenbare naam',
    'generalized_pattern_label' => 'Gegeneraliseerd patroon',
    'no_prefix_warning' => 'Er is geen gedeeld voorvoegsel van 4 tekens gevonden over de geselecteerde aliassen — typ handmatig een patroon voordat je bevestigt.',
    'confirm_merge' => 'Samenvoegen bevestigen',

    'flash' => [
        'updated' => 'Alias bijgewerkt.',
        'deleted' => 'Alias verwijderd.',
        'merged' => 'Aliassen samengevoegd.',
        'imported' => ':count alias geïmporteerd.|:count aliassen geïmporteerd.',
        'nothing' => 'Niets te importeren.',
    ],

    'errors' => [
        'not_found' => 'Alias niet gevonden (mogelijk verwijderd in een ander tabblad).',
        'pattern_empty' => 'Gegeneraliseerd patroon mag niet leeg zijn.',
        'select_two' => 'Selecteer minstens twee aliassen om samen te voegen.',
        'some_not_found' => 'Een of meer geselecteerde aliassen zijn niet gevonden.',
        'both_required' => 'Herkenbare naam en gegeneraliseerd patroon zijn beide vereist.',
        'merge_not_found' => 'Een of meer aliassen zijn niet gevonden (mogelijk verwijderd in een ander tabblad).',
        'merge_failed' => 'Samenvoegen mislukt (:class).',
        'no_file' => 'Geen bestand geüpload.',
        'unreadable' => 'Kon het geüploade bestand niet lezen.',
        'too_short' => 'Patroon is te kort om te testen.',
    ],
];
