<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'À propos de :subject',
        'close' => 'Fermer',
    ],

    'page_title' => 'Où sont mes données ?',
    'intro' => 'Beatrax stocke tout sur cet appareil. Il n\'y a pas de serveur Beatrax ni de compte dans le cloud. Ce qui sort, c\'est seulement ce que tu connectes toi-même — une boîte de réception, une banque via Enable Banking, les appareils que tu associes pour la synchronisation — plus une consultation quotidienne des taux de change. Chaque connexion le dit sur l\'écran où tu l\'actives.',

    'lives_here' => 'Tes données sont ici',
    'copy' => 'Copier',
    'copied' => 'Copié',

    'location' => [
        'database' => 'Base de données :',
        'artefacts_imports' => 'Relevés importés :',
        'artefacts_mail' => 'Courriels analysés :',
        'artefacts_drop' => 'Dossier de dépôt surveillé :',
        'backups' => 'Sauvegardes :',
        'secrets' => 'Identifiants des connecteurs :',
        'logs' => 'Journaux :',
    ],

    'copy_aria' => [
        'database' => 'Copier le chemin de la base de données dans le presse-papiers',
        'artefacts_imports' => 'Copier le chemin des relevés importés dans le presse-papiers',
        'artefacts_mail' => 'Copier le chemin des courriels analysés dans le presse-papiers',
        'artefacts_drop' => 'Copier le chemin du dossier de dépôt surveillé dans le presse-papiers',
        'backups' => 'Copier le chemin des sauvegardes dans le presse-papiers',
        'secrets' => 'Copier le chemin des identifiants des connecteurs dans le presse-papiers',
        'logs' => 'Copier le chemin des journaux dans le presse-papiers',
    ],

    'artefacts_heading' => 'Tes documents sources ne sont pas dans la sauvegarde',
    'artefacts_body' => 'Une sauvegarde contient la base de données et rien d\'autre. Les relevés que tu as importés, les courriels récupérés par l\'analyseur et les reçus déposés dans le dossier surveillé restent où ils sont, dans les trois dossiers listés ci-dessus. Mettre une sauvegarde à l\'abri ne les copie pas : une archive complète suppose d\'emporter ces dossiers aussi — ou d\'utiliser Tout exporter ci-dessous, qui les empaquette avec la sauvegarde.',

    'export_heading' => 'Tout exporter',
    'export_body' => 'Une seule archive contenant une copie chiffrée de ta base de données et chaque document source que tu as confié à Beatrax. Décompresse-la où tu veux : tes documents s\'y trouvent tels qu\'ils ont toujours été, dans les dossiers dont ils viennent.',
    'export_passphrase_label' => 'Phrase secrète pour la base de données',
    'export_confirm_label' => 'Répète la phrase secrète',
    'export_passphrase_hint' => 'La base de données à l\'intérieur de l\'archive est chiffrée avec cette phrase secrète et rien ne permet de l\'ouvrir sans elle : choisis donc quelque chose que tu auras encore plus tard. Tes documents sources y entrent tels quels, alors garde l\'archive dans un endroit de confiance.',
    'export_cta' => 'Tout exporter en ZIP',
    'export_working' => 'Création de l\'archive…',

    'delete_heading' => 'Supprimer tes données',
    'delete_intro' => 'Tes données sont des fichiers sur cet appareil : les supprimer, c\'est supprimer ces fichiers. Il n\'y a ici aucun bouton qui le fasse pour toi, et c\'est volontaire : c\'est le système de fichiers qui détient réellement ton historique, et un bouton qui viderait quelques tables en laissant les fichiers en place serait pire que rien.',
    'delete_uninstall' => 'Désinstaller Beatrax ne supprime pas tes données. C\'est délibéré — une désinstallation accidentelle ne doit pas détruire des années d\'historique — donc tout ce qui suit reste sur cet appareil tant que tu ne l\'enlèves pas toi-même.',
    'delete_list_intro' => 'Pour ne laisser aucune trace, supprime chacun de ces éléments :',
    'delete_journal_note' => 'La base de données est accompagnée de deux fichiers de journal, :wal et :shm. Tes modifications les plus récentes y vivent tant qu\'elles ne sont pas intégrées à la base, alors supprime les trois ensemble.',
    'no_telemetry' => 'Il n\'y a aucune télémétrie à désactiver et aucun compte distant à fermer.',
];
