<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Cette application ne peut pas remettre de fichier à ton appareil ; la sauvegarde chiffrée se fait donc dans l’application de bureau. Associe cet appareil pour garder les deux synchronisés.',
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
        'restored' => 'Votre sauvegarde a été restaurée. Connectez-vous avec le nom d’utilisateur et le mot de passe en vigueur lors de sa création.',
        'snapshot_saved_prefix' => 'Un instantané de tes données précédentes a été enregistré dans',
        'file_label' => "Fichier de sauvegarde (.enc) ou archive d'export (.zip)",
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
        'choose_file' => "Choisis à partir de quoi restaurer : le fichier de sauvegarde .enc, ou le .zip écrit par l'export en un clic.",
        'upload_failed' => 'Le fichier n’a pas fini d’être téléversé. Il est peut-être trop volumineux pour cet appareil — la restauration dans l’application de bureau accepte une sauvegarde plus grande.',
        'enter_passphrase' => 'Saisis la phrase secrète avec laquelle la sauvegarde a été chiffrée.',
        'unreadable' => 'Le fichier envoyé n\'a pas pu être lu. Réessaie.',
        'restore_wrong_passphrase' => "Cette phrase secrète n'a pas ouvert cette sauvegarde, et rien n'a été modifié. Retape-la et réessaie. Si elle est bien la bonne, le fichier a été altéré depuis sa création : restaure alors une autre copie.",
        'restore_not_a_backup' => "Ce fichier ne contient aucune sauvegarde Beatrax, il n'y a donc rien à restaurer et rien n'a été modifié. Choisis le fichier .enc écrit par l'app au moment de la sauvegarde, ou le .zip écrit par l'export en un clic.",
        'restore_contents_unreadable' => "La sauvegarde s'est ouverte, mais la base de données qu'elle contient est endommagée : elle n'a pas été restaurée et rien n'a été modifié. Restaure une sauvegarde plus ancienne.",
        'restore_could_not_read' => "Le fichier de sauvegarde n'a pas pu être lu, la restauration n'a donc pas eu lieu et rien n'a été modifié. Vérifie qu'il reste de l'espace libre sur cet appareil, puis réessaie.",
        'restore_not_supported' => "La restauration fonctionne sur la version qui garde ses données dans un seul fichier, ce qui n'est pas le cas ici, et rien n'a été modifié. Sur une base de données serveur, utilise les outils de restauration de cette base.",
        'restore_failed' => "La restauration n'a pas eu lieu et rien n'a été modifié. Réessaie — si l'échec persiste, le journal de l'app note ce qui l'a arrêtée.",
    ],
];
