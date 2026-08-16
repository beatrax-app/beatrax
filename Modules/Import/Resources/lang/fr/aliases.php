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

    'diff_new' => 'nouveaux,',
    'diff_unchanged' => 'inchangés,',
    'diff_conflicts' => 'conflits.',

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
    'matches_prefix' => 'Correspond à',
    'matches_suffix' => 'transactions dans ton historique récent.',

    'merge_modal_title' => 'Fusionner :count alias',

    'merge_modal_help_html' => 'La ligne restante garde son libellé brut ; les lignes absorbées sont conservées dans <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Nom lisible',
    'generalized_pattern_label' => 'Motif généralisé',
    'no_prefix_warning' => 'Aucun préfixe commun de 4 caractères n\'a été trouvé parmi les alias sélectionnés — saisis un motif à la main avant de confirmer.',
    'confirm_merge' => 'Confirmer la fusion',

    'flash' => [
        'updated' => 'Alias mis à jour.',
        'deleted' => 'Alias supprimé.',
        'merged' => 'Alias fusionnés.',
        'imported' => ':count alias importés.',
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
    ],
];
