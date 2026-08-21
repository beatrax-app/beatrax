<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Affichage',
        'money' => 'Argent',
        'insights' => 'Analyses et alertes',
        'security' => 'Sécurité et appareils',
        'data' => 'Imports et données',
        'app' => 'Application',
    ],

    'title' => 'Paramètres',
    'subtitle' => 'Préférences d\'affichage de tes finances dans l\'application.',

    'appearance' => [
        'heading' => 'Apparence',
        'theme' => 'Thème',
        'theme_light' => 'Clair',
        'theme_dark' => 'Sombre',
        'theme_system' => 'Système',
        'theme_help' => 'Système suit le mode clair ou sombre de ton système d\'exploitation.',
    ],

    'language' => [
        'apply' => 'Appliquer',
        'heading' => 'Langue',
        'label' => 'Langue d\'affichage',

        'system' => 'Système',
        'help' => 'Système suit la langue de ton navigateur ou de ton système d\'exploitation, avec l\'anglais par défaut.',
    ],

    'currency_display' => [
        'heading' => 'Affichage des devises',
        'label' => 'Vue par défaut dans la liste des transactions',
        'eur_only' => 'EUR uniquement',
        'original' => 'Devise d\'origine',
        'help' => 'Tu peux toujours changer page par page depuis la liste des transactions.',
    ],

    'base_currency' => [
        'heading' => 'Devise de référence',
        'label' => 'Devise des rapports',
        'help' => 'Tous les totaux et cumuls sont convertis dans cette devise. Chaque compte affiche toujours sa propre devise d\'origine à côté.',
    ],

    'exchange_rates' => [
        'heading' => 'Taux de change',
        'fetch_online' => 'Récupérer les taux actuels en ligne',
        'online_on' => 'Taux récupérés chaque jour auprès de la BCE. Uniquement des recherches de paires de devises — aucune donnée personnelle.',
        'last_updated' => 'Dernière mise à jour : :date.',
        'online_off' => 'Les taux fournis avec l\'application sont utilisés. Aucune donnée ne quitte cet appareil.',
        'fetch_aria' => 'Récupérer les taux de change actuels en ligne',
        'refreshing' => 'Actualisation…',
        'next_refresh' => 'Prochaine actualisation automatique : tous les jours à 09:00',
        'refresh_gave_up' => 'Impossible d’actualiser les taux. Les taux déjà présents sur cet appareil restent utilisés.',
        'refresh_now' => 'Actualiser maintenant',
    ],

    'period' => [
        'heading' => 'Période',
        'label' => 'La période commence le jour',
        'help' => 'Numéroté de 1 à 28. La plupart des gens laissent 1 (mois calendaire). Mets 25 si ton salaire arrive le 25 et que « ton mois » commence pour toi à ce moment-là.',
    ],

    'recurring' => [
        'heading' => 'Détection des récurrences',
        'window_label' => 'Fenêtre de détection (mois)',
        'window_help' => 'Nombre de mois d\'historique à analyser pour regrouper les transactions en schémas récurrents.',
        'income_label' => 'Revenu minimum (centimes)',
        'income_help' => 'Les revenus sous ce seuil ne sont pas regroupés automatiquement. Stocké en centimes — 200000 signifie 2 000,00 €. Mets 0 pour désactiver le seuil.',
    ],

    'drift' => [
        'heading' => 'Alertes de dérive',
        'label' => 'Seuil d\'alerte de dérive par défaut',
        'help' => 'Les alertes se déclenchent quand le dernier montant d\'un débit récurrent s\'écarte du montant précédent de plus que ce pourcentage. Les valeurs définies par série sont prioritaires.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (par défaut)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Enregistrer les paramètres',
    'saved' => 'Enregistré.',

    'anomaly_heading' => 'Détection des anomalies',
    'notifications_heading' => 'Notifications',

    'forecasting' => [
        'heading' => 'Prévisions',
        'intro' => 'Beatrax projette ton solde à partir de l\'état actuel de tes comptes. Pour les comptes sans solde de relevé (PayPal, anciens imports CSV), indique ici le solde d\'ouverture pour que les projections partent d\'un point connu.',
        'no_accounts' => 'Pas encore de comptes — importe un relevé pour en ajouter un.',
    ],

    'auto_import' => [
        'heading' => 'Import automatique',
        'label' => 'Import automatique depuis le dossier de dépôt',

        'active_html' => 'Le dossier de dépôt est actif. Beatrax analyse <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> toutes les 5 minutes à la recherche de nouveaux fichiers.',
        'inactive_html' => 'Une fois activé, Beatrax analyse <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> toutes les 5 minutes à la recherche de fichiers <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> et <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> et les importe par le même processus de correspondance que l\'assistant. Les fichiers traités sont déplacés vers <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> pour ne jamais être importés deux fois.',
    ],

    'aliases' => [
        'heading' => 'Alias',
        'intro' => 'Consulte et modifie les noms lisibles que tu as appris à Beatrax pour les libellés de relevé obscurs.',
        'manage' => 'Gérer les alias →',
    ],

    'tax_heading' => 'Impôts',
    'shared_merchant_heading' => 'Liste partagée des commerçants',
    'data_backup_heading' => 'Données et sauvegarde',
    'install_heading' => 'Installation',

    'about_updates' => [
        'heading' => 'À propos des mises à jour',
        'body' => 'Beatrax se met à jour automatiquement une fois installé. Après l\'installation de la toute première version, les versions suivantes arrivent via une bannière dans l\'application — tu n\'as pas besoin de retourner sur GitHub. Si une mise à jour échoue un jour, tu peux toujours retélécharger le dernier installeur manuellement depuis la page des versions.',
        'open_releases' => 'Ouvrir la page des versions →',
    ],

    'privacy' => [
        'heading' => 'Politique de confidentialité',
        'body' => 'Beatrax garde tes finances sur tes propres appareils. La politique explique ce que cela veut dire, ce qu’envoient les fonctions en ligne facultatives et comment supprimer tes données.',
        'open' => 'Lire la politique de confidentialité →',
        'url_hint' => 'Si le lien ne s’ouvre pas, va sur :',
    ],

    'first_run_tour' => [
        'heading' => 'Visite guidée initiale',
        'body' => 'Relance l\'assistant de configuration si tu veux repasser par le parcours d\'introduction.',
        'run_again' => 'Relancer l\'assistant de configuration',
    ],

    'developer' => [
        'heading' => 'Développeur',
        'label' => 'Dev Console intégrée',
        'help' => 'Affiche la Dev Console sur /dev. Réinitialise l\'option Avancé à chaque connexion.',
        'aria' => 'Mode développeur',
    ],

    'errors' => [
        'currency_required' => 'Choisis une devise.',
        'window_months' => 'Choisis entre 2 et 60 mois.',
        'threshold' => 'Choisis un seuil parmi 1%, 2%, 5%, 10%, 25% ou 50%.',
        'amount' => 'Saisis un montant à partir de 0 €.',
        'period_day' => 'Choisis un jour de 1 à 28.',
        'currency_view' => 'Choisis l\'une des options disponibles.',
    ],
];
