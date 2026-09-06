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
        'help' => "Change les mots affichés à l'écran et la façon dont les montants sont écrits. Système suit la langue de ton navigateur ou de ton système d'exploitation, avec l'anglais par défaut.",
    ],

    'timezone' => [
        'heading' => 'Fuseau horaire',
        'label' => 'Fuseau horaire de cette installation',
        'help' => 'Détermine le jour auquel une transaction appartient et le référentiel dans lequel les heures sont enregistrées. Les appareils appairés partagent ce réglage, pour que les deux lisent le même jour.',
        'this_machine' => 'Cet appareil (:zone)',
    ],

    'sample_data' => [
        'heading' => 'Données d’exemple',
        'help' => 'Remplit ce compte avec un livre inventé — comptes, transactions, budgets, objectifs et alertes — pour qu’il y ait quelque chose à regarder. Cela s’ajoute à ce qui est déjà là, et rien n’appartient à une personne réelle.',
        'warning' => 'Cela écrit dans ton propre livre et atteint tes appareils appairés. Il n’y a pas d’annulation depuis cet écran.',
        'confirm' => 'L’ajouter à ce compte',
        'cancel' => 'Annuler',
        'load' => 'Charger des données d’exemple',
        'working' => 'Construction du livre d’exemple. Cela prend un instant.',
        'loaded' => 'Données d’exemple ajoutées (:count).',
    ],

    'country' => [
        'heading' => 'Pays',
        'label' => 'Ton pays',
        'help' => "Détermine de quel pays proviennent les règles fiscales, les administrations et les frais bancaires que l'app reconnaît. Cela ne change ni la langue ni la façon dont les montants sont écrits.",
        'choose' => 'Choisis un pays…',
        'switch_note' => 'Changer de pays ajoute de nouvelles catégories — les marquages existants ne sont jamais modifiés.',

        'wording_note' => 'Les noms des catégories fiscales apparaissent dans votre langue ; la déclaration de revenus de :country utilise ses propres termes.',

        'countries' => [
            'at' => 'Autriche',
            'be' => 'Belgique',
            'bg' => 'Bulgarie',
            'ca' => 'Canada',
            'ch' => 'Suisse',
            'cy' => 'Chypre',
            'cz' => 'Tchéquie',
            'de' => 'Allemagne',
            'dk' => 'Danemark',
            'ee' => 'Estonie',
            'es' => 'Espagne',
            'fi' => 'Finlande',
            'fr' => 'France',
            'gb' => 'Royaume-Uni',
            'gr' => 'Grèce',
            'hr' => 'Croatie',
            'hu' => 'Hongrie',
            'ie' => 'Irlande',
            'is' => 'Islande',
            'it' => 'Italie',
            'lt' => 'Lituanie',
            'lu' => 'Luxembourg',
            'lv' => 'Lettonie',
            'mt' => 'Malte',
            'nl' => 'Pays-Bas',
            'no' => 'Norvège',
            'pl' => 'Pologne',
            'pt' => 'Portugal',
            'ro' => 'Roumanie',
            'se' => 'Suède',
            'si' => 'Slovénie',
            'sk' => 'Slovaquie',
            'us' => 'États-Unis',
        ],
    ],

    'currency_display' => [
        'heading' => 'Affichage des montants',
        'label' => 'Vue par défaut des montants',
        'eur_only' => 'Montant réglé',
        'original' => 'Montant d\'origine',
        'help' => 'S\'applique à la liste des transactions et aux totaux du tableau de bord. Tu peux toujours changer page par page, mais seulement depuis la liste des transactions.',
    ],

    'base_currency' => [
        'heading' => 'Devise de référence',
        'label' => 'Devise des rapports',
        'help' => 'Tous les totaux et cumuls sont convertis dans cette devise. Chaque compte affiche toujours sa propre devise d\'origine à côté.',
    ],

    'exchange_rates' => [
        'heading' => 'Taux de change',
        'fetch_online' => 'Récupérer les taux actuels en ligne',
        'online_on' => 'Taux récupérés chaque jour auprès de la BCE, ou auprès de Frankfurter si la BCE est injoignable. Uniquement des recherches de paires de devises — aucune donnée personnelle.',
        'last_updated' => 'Dernière mise à jour : :date.',
        'online_off' => 'Les taux déjà présents restent utilisés, l’instantané intégré servant de secours. Aucune donnée ne quitte cet appareil.',
        'fetch_aria' => 'Récupérer les taux de change actuels en ligne',
        'refreshing' => 'Actualisation…',
        'next_refresh' => 'Actualisation automatique : une fois par jour',
        'refresh_gave_up' => 'Impossible d’actualiser les taux. Les taux déjà présents sur cet appareil restent utilisés.',
        'refresh_now' => 'Actualiser maintenant',
    ],

    'period' => [
        'heading' => 'Période',
        'label' => 'La période commence le jour',
        'help' => 'Numéroté de 1 à 28. La plupart des gens laissent 1 (mois calendaire). Mets 25 si ton salaire arrive le 25 et que « ton mois » commence pour toi à ce moment-là.',

        'move_confirm' => 'Si la période commence le jour :day, tous les montants des enveloppes sont reclassés et additionnés deux à deux là où deux mois n’en font plus qu’un. Remettre le jour comme avant ne les sépare pas.',
        'move_cancel' => 'Annuler',
        'move_apply' => 'Appliquer',
    ],

    'recurring' => [
        'heading' => 'Détection des récurrences',
        'window_label' => 'Fenêtre de détection (mois)',
        'window_help' => 'Nombre de mois d\'historique à analyser pour regrouper les transactions en schémas récurrents.',
        'income_label' => 'Revenu minimum (sous-unités)',
        'income_help' => 'Les revenus sous ce seuil ne sont pas regroupés automatiquement. Stocké en sous-unités — :minor signifie :example. Mets 0 pour désactiver le seuil.',
    ],

    'drift' => [
        'heading' => 'Alertes de dérive',
        'label' => 'Seuil d\'alerte de dérive par défaut',
        'help' => 'Les alertes se déclenchent quand le dernier montant d\'un débit récurrent s\'écarte du montant précédent de plus que ce pourcentage. Les valeurs définies par série sont prioritaires.',
        'options' => [
            '1' => '±1 %',
            '2' => '±2 %',
            '5' => '±5 % (par défaut)',
            '10' => '±10 %',
            '25' => '±25 %',
            '50' => '±50 %',
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
        'active_phone_html' => 'Le dossier de dépôt est actif. Beatrax analyse <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> en arrière-plan à la recherche de nouveaux fichiers. C\'est ton téléphone qui décide quand une analyse en arrière-plan démarre — cela peut prendre quelques minutes ou quelques heures.',
        'inactive_phone_html' => 'Une fois activé, Beatrax analyse <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> en arrière-plan à la recherche de fichiers <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> et <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> et les importe par le même processus de correspondance que l\'assistant. C\'est ton téléphone qui décide quand une analyse en arrière-plan démarre — cela peut prendre quelques minutes ou quelques heures. Les fichiers traités sont déplacés vers <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> pour ne jamais être importés deux fois.',
    ],

    'aliases' => [
        'heading' => 'Alias',
        'intro' => 'Consulte et modifie les noms lisibles que tu as appris à Beatrax pour les libellés de relevé obscurs.',
        'manage' => 'Gérer les alias →',
    ],

    'tax_heading' => 'Impôts',
    'data_backup_heading' => 'Données et sauvegarde',

    'about_updates' => [
        'heading' => 'À propos des mises à jour',
        'body' => 'Beatrax se met à jour automatiquement une fois installé. Après l\'installation de la toute première version, les versions suivantes arrivent via une bannière dans l\'application — tu n\'as pas besoin de retourner sur GitHub. Si une mise à jour échoue un jour, tu peux toujours retélécharger le dernier installeur manuellement depuis la page des versions.',
        'body_phone' => 'Ici, Beatrax ne se met pas à jour tout seul. Les nouvelles versions de l\'app mobile arrivent par l\'App Store ou Google Play, comme tes autres applications.',
        'check_label' => 'Rechercher les mises à jour automatiquement',
        'check_on' => "Beatrax demande au flux des versions s'il existe une version signée plus récente. Rien n'est téléchargé tant que tu ne choisis pas de l'installer.",
        'check_off' => "Aucune recherche de mise à jour n'est faite et rien ne quitte cet appareil. Les nouvelles versions se trouvent en ouvrant toi-même la page des versions.",
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
        'period_move_failed' => 'Le mois budgétaire n’a pas pu être déplacé, il est donc resté où il était.',
        'currency_required' => 'Choisis une devise.',
        'window_months' => 'Choisis entre 2 et 60 mois.',
        'threshold' => 'Choisis un seuil parmi 1 %, 2 %, 5 %, 10 %, 25 % ou 50 %.',
        'amount' => 'Saisis un montant à partir de :zero.',
        'period_day' => 'Choisis un jour de 1 à 28.',
        'currency_view' => 'Choisis l\'une des options disponibles.',
        'timezone' => 'Choisis un fuseau horaire dans la liste.',
    ],
];
