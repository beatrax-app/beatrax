<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Alertes système',

    'actions' => [
        'install_next_launch' => 'Installer au prochain démarrage',
        'install_next_launch_aria' => 'Installer au prochain démarrage — marque l\'alerte système #:id comme résolue',
        'skip_version' => 'Ignorer cette version',
        'release_notes' => 'Notes de version →',
        'update_now' => 'Mettre à jour maintenant',
        'update_now_aria' => 'Mettre à jour maintenant — marque l\'alerte système #:id comme résolue',
        'remind_later' => 'Me le rappeler plus tard',
        'mark_resolved' => 'Marquer comme résolue',
        'mark_resolved_aria' => 'Marquer comme résolue — alerte système #:id',
    ],

    'messages' => [
        'update_available' => 'Mise à jour disponible — Beatrax :version est prêt. Il s\'installera au prochain démarrage.',
        'update_stale' => 'Tu utilises la version :current — la version :latest est disponible depuis 30 jours. Mets à jour maintenant.',
        'update_critical' => 'Mise à jour critique disponible — la version :version corrige :summary. Installe-la dès que possible.',
        'backup_corrupt_with_path' => 'La sauvegarde écrite le :timestamp n\'a pas passé le contrôle d\'intégrité. Examine :path. Règle le problème avant de compter sur tes sauvegardes.',
        'backup_corrupt_no_path' => 'La sauvegarde tentée le :timestamp s\'est interrompue avant qu\'aucun fichier ne soit produit — la base source n\'a pas passé le contrôle d\'intégrité. Règle le problème avant de compter sur tes sauvegardes.',

        'backup_overdue' => 'La sauvegarde vérifiée la plus récente date de :hoursh. Exécute <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> ou attends l\'exécution planifiée de 03:00.',
        'wal_mode_missing' => 'SQLite n\'est pas en mode WAL (actuellement :mode). Les écritures simultanées peuvent se bloquer. Exécute <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> pour obtenir de l\'aide.',
        'synchronous_misconfigured' => 'Le niveau synchronous de SQLite est :level (NORMAL/1 attendu). La sémantique de durabilité peut différer de la configuration. Exécute <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> pour obtenir de l\'aide.',
        'oauth_scrub_set_failed' => 'Le masquage des secrets OAuth est hors service. Les journaux et les extraits d’audit peuvent contenir des jetons non masqués jusqu’au prochain chargement réussi.',
        'oauth_reauth_required' => 'Les secrets OAuth ont été déplacés vers un stockage par utilisateur. Réautorisez Gmail et Microsoft pour reprendre l’analyse des e-mails. L’ancien fichier de secrets a été renommé :file pour permettre un retour en arrière.',
        'oauth_reconsent' => 'Reconnectez votre :provider',
        'reconnect_link' => 'Reconnecter →',
    ],
];
