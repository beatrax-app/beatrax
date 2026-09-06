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
        'db_backup' => ['label' => 'Sauvegarder la base', 'description' => "Écrit une copie SQLite horodatée dans le dossier des sauvegardes, sauf si la base n'a pas changé depuis la dernière. Une copie conservée supprime aussi les sauvegardes plus anciennes selon la règle de rétention."],
        'doctor' => ['label' => 'Lancer doctor', 'description' => 'Lance la suite de sondes opérationnelles et indique pass / warn / fail pour chaque ligne. Une ligne warn ou fail donne un code de sortie non nul.'],
        'failed_jobs' => ['label' => 'Purger les jobs échoués', 'description' => 'Supprime de la table failed_jobs gérée par Laravel toutes les lignes de plus de 30 jours, que le job ait été relancé ou non.'],
        'cache_clear' => ['label' => 'Vider le cache', 'description' => "Vide le cache de l'application."],
        'route_list' => ['label' => 'Lister les routes', 'description' => 'Affiche chaque route HTTP enregistrée sur la sortie standard.'],
        'config_show' => ['label' => 'Afficher la configuration', 'description' => "Affiche un fichier de configuration entier, ou la valeur d'une clé pointée qu'il contient."],
        'view_clear' => ['label' => 'Vider le cache des vues', 'description' => 'Vide le cache des vues Blade compilées.'],
        'queue_retry' => ['label' => 'Relancer les jobs échoués', 'description' => 'Relance un job échoué par identifiant, ou tous les jobs échoués si tu passes `all`.'],
        'rederive_fingerprints' => ['label' => 'Recalculer les empreintes', 'description' => "Recalcule l'empreinte de chaque transaction encore en dessous de la version de normalisation actuelle. Lancée d'ici, la commande indique le nombre et n'écrit rien."],
        'demo_seed' => ['label' => 'Charger des données d’exemple', 'description' => 'Ajoute un livre d’exemple — comptes, transactions, budgets, objectifs et alertes — inventé pour regarder l’application avec quelque chose dedans. Il s’ajoute à ce qui est déjà là au lieu de le remplacer, et rien n’appartient à une personne réelle.'],
        'db_restore' => ['label' => 'Restaurer la base', 'description' => 'Remplace la base actuelle par le fichier de sauvegarde indiqué.'],
        'regenerate_recovery_codes' => ['label' => 'Régénérer les codes de secours', 'description' => "Régénère les 10 codes de secours à usage unique d'un utilisateur."],
        'grant_dev' => ['label' => "Accorder l'accès développeur", 'description' => "Passe is_developer à true pour l'utilisateur indiqué."],
        'install' => ['label' => "Lancer l'installation", 'description' => "Configuration initiale idempotente : le schéma de la base, les données de référence et l'unique compte utilisateur. Relancée sur une installation déjà configurée, elle reconfirme le compte existant et laisse le mot de passe inchangé."],
    ],

    'arg' => [
        'action' => ['label' => 'Action'],
        'config' => ['label' => 'Clé de configuration', 'help' => 'Le fichier de configuration ou la clé pointée à afficher, par exemple `app` ou `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Identifiant du job', 'help' => "Saisis `all` pour relancer tous les jobs échoués, ou un identifiant de job pour n'en relancer qu'un. Laissé vide, le champ ne relance rien.", 'placeholder' => 'all (ou un identifiant précis)'],
        'queue' => ['label' => 'Nom de la file', 'help' => 'Filtre de file facultatif ; toutes les files par défaut.', 'placeholder' => 'default'],
        'path' => ['label' => 'Chemin du fichier de sauvegarde', 'help' => 'Remplace la base actuelle par le fichier situé au chemin indiqué.', 'placeholder' => '/chemin/vers/backup.sqlite'],
        'username' => ['label' => "Nom d'utilisateur", 'placeholder' => 'alice'],
    ],
];
