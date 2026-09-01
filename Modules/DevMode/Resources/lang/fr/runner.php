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
    // i18n-review: fr · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Aucune exécution pour l\'instant. Touche sur « Lancer une commande » ou utilise la palette de commandes (⌘K).',
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
        'invalid_args' => 'Impossible de lancer :command — :reason',
        'arg' => 'argument|arguments',
        'started' => 'Démarré :command (exécution :runId)',
        'run_expired' => 'Enregistrement de l\'exécution expiré — impossible de relancer.',
        'reran' => 'Relancé :command (exécution :runId)',
        'rerun_forbidden' => 'Cette exécution appartient à un autre développeur.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Sauvegarder la base', 'description' => 'Écrit une copie SQLite horodatée dans le dossier des sauvegardes (ou au chemin indiqué).'],
        'doctor' => ['label' => 'Lancer doctor', 'description' => 'Indique les versions installées de PHP / Composer / SQLite et vérifie les minimums.'],
        'failed_jobs' => ['label' => 'Purger les jobs échoués', 'description' => 'Purge les entrées traitées de la table failed_jobs gérée par Laravel.'],
        'cache_clear' => ['label' => 'Vider le cache', 'description' => "Vide le cache de l'application."],
        'route_list' => ['label' => 'Lister les routes', 'description' => 'Affiche chaque route HTTP enregistrée sur la sortie standard.'],
        'config_show' => ['label' => 'Afficher la configuration', 'description' => 'Affiche la valeur de la clé de configuration indiquée.'],
        'view_clear' => ['label' => 'Vider le cache des vues', 'description' => 'Vide le cache des vues Blade compilées.'],
        'queue_retry' => ['label' => 'Relancer les jobs échoués', 'description' => 'Relance un job (par identifiant) ou tous les jobs échoués (identifiant vide).'],
        'rederive_fingerprints' => ['label' => 'Recalculer les empreintes', 'description' => "Recalcule l'empreinte de chaque transaction avec la version de normalisation actuelle."],
        'db_restore' => ['label' => 'Restaurer la base', 'description' => 'Remplace la base actuelle par le fichier de sauvegarde indiqué.'],
        'migrate_fresh' => ['label' => 'Supprimer les tables et remigrer', 'description' => 'Supprime toutes les tables, puis relance toutes les migrations.'],
        'reset_password' => ['label' => 'Réinitialiser un mot de passe', 'description' => "Réinitialise un mot de passe utilisateur de façon interactive (refuse l'usage non interactif)."],
        'regenerate_recovery_codes' => ['label' => 'Régénérer les codes de secours', 'description' => "Régénère les 10 codes de secours à usage unique d'un utilisateur."],
        'grant_dev' => ['label' => "Accorder l'accès développeur", 'description' => "Passe is_developer à true pour l'utilisateur indiqué."],
        'install' => ['label' => "Lancer l'installation", 'description' => 'Configuration initiale idempotente. La relancer sur une installation déjà configurée est destructeur.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Fichier de destination', 'help' => 'Laisse vide pour utiliser le dossier de sauvegardes par défaut.', 'placeholder' => '/chemin/vers/backup.sqlite (facultatif)'],
        'action' => ['label' => 'Action'],
        'config' => ['label' => 'Clé de configuration', 'help' => 'Le fichier de configuration ou la clé pointée à afficher, par exemple `app` ou `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Identifiant du job', 'help' => "Laisse vide pour relancer tous les jobs échoués ; indique un identifiant pour n'en relancer qu'un.", 'placeholder' => 'tous (ou un identifiant précis)'],
        'queue' => ['label' => 'Nom de la file', 'help' => 'Filtre de file facultatif ; toutes les files par défaut.', 'placeholder' => 'default'],
        'from' => ['label' => 'Chemin du fichier de sauvegarde', 'help' => 'Remplace la base actuelle par le fichier situé au chemin indiqué.', 'placeholder' => '/chemin/vers/backup.sqlite'],
        'username' => ['label' => "Nom d'utilisateur", 'placeholder' => 'alice'],
    ],
];
