<?php

declare(strict_types=1);

return [
    'tables' => 'Tables',
    'schema_viewer_aria' => 'Visualiseur de schéma',
    'columns' => 'colonnes',
    'indexes' => 'index',
    'foreign_keys' => 'clés étrangères',
    'browse' => 'Parcourir',
    'heading' => 'SQL',

    'subtitle_html' => 'Panneau de requêtes SELECT uniquement. Le validateur (à l\'analyse) et le PRAGMA <code class="font-mono text-xs">query_only = 1</code> (au moteur) rejettent tout ce qui n\'est pas un SELECT. Limite stricte de 5 secondes en temps réel.',
    'advanced_off_strong' => 'Le mode Advanced est DÉSACTIVÉ.',
    'advanced_off_hint' => 'Active Advanced (Dev Mode → Advanced) pour lancer des requêtes.',
    'statement_label' => 'Instruction SELECT',
    'run' => 'Exécuter',
    'rows_meta' => ':rows ligne · :durationms|:rows lignes · :durationms',
    'no_rows' => 'La requête n\'a renvoyé aucune ligne.',

    'errors' => [
        'advanced_off' => 'Active Advanced (Dev Mode → Advanced) pour lancer des requêtes.',
        'only_select' => 'Seules les instructions SELECT sont autorisées. Motif du rejet : :reason.',
        'timeout' => 'La requête a dépassé le délai de 5 secondes. Affine ta requête et réessaie.',
        'engine' => 'Erreur SQL : :message',
        'unknown_table' => 'Table inconnue.',
    ],
];
