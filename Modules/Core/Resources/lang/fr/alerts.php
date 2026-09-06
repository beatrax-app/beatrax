<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Alertes système',

    'actions' => [
        'download_and_install' => 'Télécharger et installer',
        'download_and_install_aria' => "Télécharger et installer — marque l'alerte système #:id comme résolue",
        'skip_version' => 'Ignorer cette version',
        'release_notes' => 'Notes de version →',
        'update_now' => 'Mettre à jour maintenant',
        'update_now_aria' => 'Mettre à jour maintenant — marque l\'alerte système #:id comme résolue',
        'remind_later' => 'Me le rappeler plus tard',
        'mark_resolved' => 'Marquer comme résolue',
        'mark_resolved_aria' => 'Marquer comme résolue — alerte système #:id',
        'assign_in_budgets' => 'Affecter dans Budgets',
        'dismiss' => 'Ignorer',
        'dismiss_aria' => 'Ignorer — alerte système #:id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'vos alertes de budget',
        'daily-triggers' => 'vos rappels quotidiens et votre récapitulatif',
    ],

    'messages' => [
        'update_available' => "Mise à jour disponible — Beatrax :version. Rien n'est téléchargé tant que tu ne choisis pas de l'installer ; ensuite Beatrax se ferme et se rouvre dans la nouvelle version.",
        'update_refused' => 'Beatrax a téléchargé la version :version et a refusé de l\'installer — le fichier ne correspondait pas à la signature de l\'éditeur, donc rien n\'a été modifié sur cet appareil. Un téléchargement endommagé peut en être la cause. Si cela se reproduit, n\'installe pas Beatrax depuis cette source.',
        'update_stale' => 'Tu utilises la version :current — la version :latest est disponible depuis 30 jours. Mets à jour maintenant.',
        'update_critical' => 'Mise à jour critique disponible — la version :version corrige :summary. Installe-la dès que possible.',
        'backup_corrupt_with_path' => 'La sauvegarde écrite le :timestamp n\'a pas passé le contrôle d\'intégrité. Examine :path. Règle le problème avant de compter sur tes sauvegardes.',
        'backup_corrupt_no_path' => 'La sauvegarde tentée le :timestamp s\'est interrompue avant qu\'aucun fichier ne soit produit — la base source n\'a pas passé le contrôle d\'intégrité. Règle le problème avant de compter sur tes sauvegardes.',
        'backup_write_failed' => 'La sauvegarde lancée à :timestamp ne s\'est pas terminée : la base de données a passé ses vérifications, ses fichiers n\'ont pas pu être écrits. Vérifie l\'espace libre et les droits du dossier de sauvegardes.',
        'backup_restore_failed' => 'La restauration lancée à :timestamp ne s\'est pas terminée. Tes données précédentes ont d\'abord été enregistrées dans :snapshot.',

        'backup_overdue' => 'La sauvegarde vérifiée la plus récente date de :hoursh. Beatrax fait cette sauvegarde lui-même, une fois par jour, pendant que l\'application est ouverte — il n\'y a rien à lancer à la main. Si elle reste aussi ancienne, l\'application n\'était pas ouverte au moment de l\'exécution quotidienne.',
        'backup_none_found' => 'Aucune sauvegarde vérifiée n\'a été trouvée dans le dossier des sauvegardes. Beatrax fait cette sauvegarde lui-même, une fois par jour, pendant que l\'application est ouverte — il n\'y a rien à lancer à la main.',
        'wal_mode_missing' => 'La base de données n\'est pas en mode WAL (actuellement :mode), l\'enregistrement peut donc s\'interrompre pendant qu\'une tâche d\'arrière-plan s\'exécute. Beatrax active WAL à chaque démarrage, un redémarrage règle donc généralement ce point.',
        'synchronous_misconfigured' => 'Le niveau de durabilité de la base de données est :level au lieu du NORMAL attendu. Beatrax le règle à chaque démarrage, un redémarrage suffit donc généralement.',
        'oauth_scrub_set_failed' => 'Le masquage des secrets OAuth est hors service. Les journaux et les extraits d’audit peuvent contenir des jetons non masqués jusqu’au prochain chargement réussi.',
        'oauth_reauth_required' => 'Les secrets OAuth ont été déplacés vers un stockage par utilisateur. Réautorisez Gmail et Microsoft pour reprendre l’analyse des e-mails. L’ancien fichier de secrets a été renommé :file pour permettre un retour en arrière.',
        'oauth_reconsent' => 'Reconnectez votre :provider',
        'auth_recovery_code_consumed' => 'Code de récupération utilisé par :username.',
        'auth_recovery_code_failed' => 'Tentative de code de récupération échouée pour :username.',
        'auth_lock_hard_cap_reached' => 'Déconnexion après trop de tentatives de code PIN échouées.',
        'open_banking_reconsent' => 'Reconnectez votre banque',
        'open_banking_nothing_imported' => 'Votre banque a envoyé des transactions, mais Beatrax n’a pu en enregistrer aucune, si bien que rien n’est arrivé dans votre registre. Ouvrez les paramètres Open banking pour voir pourquoi.',
        'auth_lock_corrupted_key' => 'Votre code PIN ne peut pas ouvrir le verrouillage de l’application sur cet appareil : la clé enregistrée est illisible. Connectez-vous avec le mot de passe de votre compte pour définir un nouveau code PIN.',
        'sync_gdk_rewrap_failed' => 'Le ré-emballage du trousseau GDK a échoué après un changement de phrase secrète du verrouillage de l’application — les données chiffrées peuvent être irrécupérables tant que le trousseau n’est pas ré-emballé.',
        'worker_crashed' => 'Le traitement en arrière-plan de Beatrax s’est arrêté de façon inattendue. Les imports et les analyses d’e-mails sont en pause. Rouvrez l’application pour le relancer.',
        'auth_lock_key_material_stranded' => 'Le chiffrement au repos est actif pour ce compte, mais plus aucune enveloppe de verrouillage d’application ne détient la clé de données : chaque note, description et détail de contrepartie chiffré est donc lu comme vide. Restaurez une sauvegarde chiffrée réalisée pendant que la clé fonctionnait encore, ou reconfigurez ce compte sur un appareil qui la détient toujours.',
        'auth_lock_recovery_wrap_stale' => 'Le mot de passe du compte a changé sans que l’enveloppe de récupération du verrouillage soit ré-emballée : ce mot de passe n’ouvre donc plus le verrouillage de l’application. Le code PIN, lui, fonctionne toujours. Reliez à nouveau le mot de passe du compte depuis les réglages de verrouillage tant que le code PIN est connu, sinon un code PIN oublié ne laisse rien derrière lui.',
        'reconnect_link' => 'Reconnecter →',
        'pots_category_link_retired' => 'La budgétisation par enveloppes a remplacé les cagnottes liées à une catégorie. :amount provenant de :count cagnotte archivée est de nouveau non affecté et attend que vous l’affectiez.|La budgétisation par enveloppes a remplacé les cagnottes liées à une catégorie. :amount provenant de :count cagnottes archivées est de nouveau non affecté et attend que vous l’affectiez.',
        'notifications_deferred_pass_failed' => "Beatrax n'a pas pu établir :pass sur cet appareil, il se peut donc qu'il en manque. Il réessaie à chaque ouverture de l'application.",
    ],
];
