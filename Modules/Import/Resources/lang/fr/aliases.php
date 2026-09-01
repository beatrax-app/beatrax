<?php

declare(strict_types=1);

return [
    'page_title' => 'Alias',
    'heading' => 'Alias',
    'subtitle' => 'Les noms lisibles que tu as appris à Beatrax pour les libellés obscurs de tes relevés. Modifie le motif généralisé d\'une ligne pour élargir ou restreindre les autres transactions qui héritent du même nom lisible.',
    'dismiss' => 'ignorer',

    'selected_count' => ':count sélectionnés',
    'merge_selected' => 'Fusionner la sélection',

    'empty_heading' => 'Pas encore d\'alias',
    'empty_body' => 'Les alias apparaissent ici quand tu cliques sur le libellé brut en italique d\'une ligne d\'aperçu d\'import et que tu lui donnes un nom lisible.',
    // i18n-review: fr · empty_body_touch — the same line for a touch
    // screen; check the verb governs this case.
    'empty_body_touch' => 'Les alias apparaissent ici quand tu touches le libellé brut en italique d\'une ligne d\'aperçu d\'import et que tu lui donnes un nom lisible.',

    'col_select' => 'Sélection',
    'col_raw' => 'Libellé brut',
    'col_generalized' => 'Motif généralisé',
    'col_friendly' => 'Nom lisible',
    'col_actions' => 'Actions',

    'select_alias_aria' => 'Sélectionner l\'alias :name',
    'generalized_pattern_aria' => 'Motif généralisé',

    'save' => 'Enregistrer',
    'cancel' => 'Annuler',
    'edit' => 'Modifier',
    'delete' => 'Supprimer',
    'delete_confirm' => 'Supprimer cet alias ? Les prochains imports de « :pattern » reviendront au libellé brut.',

    'backup_transfer' => 'Sauvegarde et transfert',
    'export_yaml' => 'Exporter les alias en YAML',

    'export_help_html' => 'Télécharge <code class="font-mono">aliases.yaml</code> au format du corpus communautaire.',
    'import_from_yaml' => 'Importer depuis un YAML',
    'parse_preview' => 'Analyser et prévisualiser',
    'cancel_import' => 'Annuler l\'import',

    'diff_summary' => ':new, :unchanged, :conflicts.',
    'diff_new' => ':count nouveau|:count nouveaux',
    'diff_unchanged' => ':count inchangé|:count inchangés',
    'diff_conflicts' => ':count conflit|:count conflits',

    'conflicts_heading' => 'Conflits',
    'conflict_name' => 'nom — existant : :existing → fichier : :file',
    'conflict_pattern_existing' => 'motif — existant :',
    'conflict_file' => '→ fichier :',
    'resolution_for_aria' => 'Résolution pour :pattern',
    'keep_yours' => 'Garder le mien',
    'replace' => 'Remplacer',
    'confirm_import' => 'Confirmer l\'import',

    'preview_aria' => 'Aperçu sur les transactions',
    'test_heading' => 'Tester sur mes transactions',
    'test_help' => 'Modifie le motif généralisé d\'une ligne pour voir à quelles transactions il correspondrait.',
    'typing' => 'Saisie…',
    'matches' => 'Correspond à :count transaction dans ton historique récent.|Correspond à :count transactions dans ton historique récent.',

    'merge_modal_title' => 'Fusionner :count alias|Fusionner :count alias',

    'merge_modal_help_html' => 'La ligne restante garde son libellé brut ; les lignes absorbées sont conservées dans <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Nom lisible',
    'generalized_pattern_label' => 'Motif généralisé',
    'no_prefix_warning' => 'Aucun préfixe commun de 4 caractères n\'a été trouvé parmi les alias sélectionnés — saisis un motif à la main avant de confirmer.',
    'confirm_merge' => 'Confirmer la fusion',

    'flash' => [
        'updated' => 'Alias mis à jour.',
        'deleted' => 'Alias supprimé.',
        'merged' => 'Alias fusionnés.',
        'imported' => ':count alias importé.|:count alias importés.',
        'nothing' => 'Rien à importer.',
    ],

    'errors' => [
        'not_found' => 'Alias introuvable (il a peut-être été supprimé dans un autre onglet).',
        'pattern_empty' => 'Le motif généralisé ne peut pas être vide.',
        'select_two' => 'Sélectionne au moins deux alias à fusionner.',
        'some_not_found' => 'Un ou plusieurs alias sélectionnés sont introuvables.',
        'both_required' => 'Le nom lisible et le motif généralisé sont tous les deux obligatoires.',
        'merge_not_found' => 'Un ou plusieurs alias sont introuvables (ils ont peut-être été supprimés dans un autre onglet).',
        'merge_failed' => 'La fusion a échoué (:class).',
        'no_file' => 'Aucun fichier envoyé.',
        'unreadable' => 'Impossible de lire le fichier envoyé.',
        'too_short' => 'Le motif est trop court pour être testé.',
        'file_not_yaml' => "Ce fichier n'est pas du YAML valide, rien n'a donc pu en être lu. Exporte de nouveau tes alias et envoie le fichier obtenu.",
        'file_unreadable_as_yaml' => "Ce fichier n'a pas pu être lu comme une liste d'alias. Exporte de nouveau tes alias et envoie le fichier obtenu.",
        'file_has_no_entries_list' => 'Ce fichier ne commence pas par une liste entries: de premier niveau, il ne contient donc aucun alias à importer. Vérifie que tu as choisi le bon fichier.',
        'entry_is_not_a_mapping' => "L'entrée :entry est une valeur simple là où un motif et un nom étaient attendus. Donne-lui les deux champs, ou supprime-la, puis envoie de nouveau le fichier.",
        'entry_is_missing_a_field' => "Il manque à l'entrée :entry son motif ou son nom, et un alias a besoin des deux. Complète ce qui manque, ou supprime cette entrée, puis envoie de nouveau le fichier.",
    ],
];
