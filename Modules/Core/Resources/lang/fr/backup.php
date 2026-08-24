<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Ce téléphone ne peut pas enregistrer un fichier que l’application lui remet ; la sauvegarde chiffrée se fait donc dans l’application de bureau. Associe cet appareil pour garder les deux synchronisés.',
        'unavailable' => 'Les sauvegardes chiffrées sont disponibles sur la version bureau (SQLite). Sur une base de données serveur, utilise les outils de sauvegarde de la base elle-même.',
        'intro' => 'Télécharge une copie chiffrée par phrase secrète de toute ta base de données — tu peux la garder sans risque sur un disque externe ou dans le cloud, car elle est illisible sans la phrase secrète (XChaCha20-Poly1305 résistant au quantique + Argon2id).',
        'passphrase' => 'Phrase secrète',
        'confirm_passphrase' => 'Confirmer la phrase secrète',
        'keep_safe' => 'Garde la phrase secrète en lieu sûr — sans elle, la sauvegarde est irrécupérable.',
        'submit' => 'Télécharger la sauvegarde chiffrée',
        'preparing' => 'Préparation…',
    ],

    'restore' => [
        'heading' => 'Restaurer depuis une sauvegarde',

        'intro_html' => 'Remplace ta base de données actuelle par une sauvegarde chiffrée. Le fichier est déchiffré et vérifié avant tout changement, et un instantané de tes données actuelles est enregistré au préalable — mais cela <strong class="text-slate-700 dark:text-slate-200">écrase tout</strong>, d\'où le verrou. Tu seras déconnecté, car ta session est elle aussi dans la base de données.',
        'restored' => 'Restauré. Recharge l\'application pour voir tes données restaurées.',
        'snapshot_saved_prefix' => 'Un instantané de tes données précédentes a été enregistré dans',
        'file_label' => 'Sauvegarde chiffrée (.enc)',
        'uploading' => 'Envoi…',
        'passphrase' => 'Phrase secrète',
        'confirm_prefix' => 'Tape',
        'confirm_suffix' => 'pour confirmer',
        'submit' => 'Restaurer (écrase les données actuelles)',
        'restoring' => 'Restauration…',
    ],

    'errors' => [
        'passphrase_min' => 'Utilise une phrase secrète d\'au moins :min caractère.|Utilise une phrase secrète d\'au moins :min caractères.',
        'passphrase_mismatch' => 'Les deux phrases secrètes ne correspondent pas.',
        'download_sqlite_only' => 'Le téléchargement chiffré n\'est disponible que sur la version SQLite.',
        'create_failed' => 'Impossible de créer la sauvegarde : :message',
        'confirm_phrase' => 'Tape :phrase pour confirmer — cela remplace tes données actuelles.',
        'choose_file' => 'Choisis un fichier de sauvegarde chiffré (.enc) à restaurer.',
        'enter_passphrase' => 'Saisis la phrase secrète avec laquelle la sauvegarde a été chiffrée.',
        'unreadable' => 'Le fichier envoyé n\'a pas pu être lu. Réessaie.',
    ],
];
