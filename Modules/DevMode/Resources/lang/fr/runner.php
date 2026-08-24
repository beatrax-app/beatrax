<?php

declare(strict_types=1);

return [
    'heading' => 'Runner Artisan',
    'subtitle' => 'Lance les commandes SAFE en un clic ; les commandes DESTRUCTIVE passent par la triple-gate.',
    'run_a_command' => 'Lancer une commande',
    'filter_aria' => 'Filtre des exécutions',
    'filter' => [
        'all' => 'Toutes',
        'running' => 'En cours',
        'failed' => 'Échouées',
        'destructive' => 'Destructives',
    ],
    'worker_running' => 'Worker de file : EN COURS',
    'worker_not_running' => 'Worker de file : ARRÊTÉ',
    'no_runs' => 'Aucune exécution pour l\'instant. Clique sur « Lancer une commande » ou utilise la palette de commandes (⌘K).',
    'recent_runs_aria' => 'Exécutions récentes',
    'modal_heading' => 'Lancer une commande SAFE',
    'modal_intro' => 'Choisis une commande de niveau SAFE à lancer immédiatement. Les commandes DESTRUCTIVE ne sont pas listées ici — utilise le bouton Relancer de la chronologie ou la palette ⌘K.',
    'args_badge' => 'args',
    'args_badge_title' => 'Ouvre un formulaire d\'arguments',

    'spawning_unavailable' => 'Les commandes Artisan tournent dans un processus séparé, et cette plateforme ne laisse pas l\'app en démarrer un. Lance-les depuis l\'app pour ordinateur.',

    'status' => [
        'running' => 'En cours',
        'done' => 'Terminé',
        'failed' => 'Échoué',
        'cancelled' => 'Annulé',
    ],
    'cancel' => 'Annuler',
    'rerun' => 'Relancer',
    'started' => 'Démarré :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Commande inconnue : :command',
        'missing_args' => 'Impossible de lancer :command — il faut :noun : :list',
        'arg' => 'argument|arguments',
        'started' => 'Démarré :command (exécution :runId)',
        'run_expired' => 'Enregistrement de l\'exécution expiré — impossible de relancer.',
        'reran' => 'Relancé :command (exécution :runId)',
    ],
];
