<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Paramètres',
        'heading' => 'Open banking',
        'subtitle' => 'Récupère automatiquement les transactions d\'ASN ou SNS via Enable Banking, un agrégateur PSD2 tiers. Désactivé par défaut.',
        'toggle_label' => 'Activer l\'open banking',
        'toggle_connected' => 'Connecté à :bank via Enable Banking.',
        'toggle_off_help' => 'Désactivé par défaut. Nécessite une acceptation unique et une configuration guidée.',
        'reconfirm_body' => 'Ton acceptation a expiré avant que la connexion ait pu aboutir. Confirme à nouveau pour terminer l\'activation de l\'open banking.',
        'reconfirm_button' => 'Confirmer à nouveau pour terminer',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Gérer l\'open banking',
        'not_connected' => 'Aucune banque connectée. Connectes-en une pour importer les transactions automatiquement.',
        'expired' => 'Consentement expiré — reconnexion nécessaire.',
        'connected' => 'Connecté à :bank via Enable Banking. Dernière synchronisation :when.',
        'never' => 'jamais',
    ],

    'transparency' => [
        'aggregator_label' => 'Agrégateur',
        'bank_label' => 'Banque',
        'consent_status_label' => 'État du consentement',
        'pill_expired' => 'Expiré — reconnecte-toi',
        'pill_expiring' => 'Expire bientôt',
        'pill_connected' => 'Connecté',
        'whats_fetched_label' => 'Ce qui est récupéré',
        'whats_fetched' => 'Transactions comptabilisées + soldes, 90 derniers jours',
        'last_successful_sync_label' => 'Dernière synchronisation réussie',
        'never' => 'Jamais',
        'last_attempt_label' => 'Dernière tentative',
        'last_attempt_failed' => ':when — échec (:reason)',
        'reason_consent_expired' => 'consentement expiré',
        'reason_error' => 'erreur',
        'disconnect_button' => 'Déconnecter',
    ],

    'consent_banner' => [
        'heading' => 'Consentement expiré — reconnecte-toi',
        'body' => 'Ta dernière synchronisation réussie remonte à :when. Reconnecte-toi pour reprendre la synchronisation automatique.',
        'never' => 'jamais',
        'reconnect' => 'Reconnecter',
    ],

    'sync' => [
        'review_import' => 'Vérifier l\'import',
        'reconnect_first' => 'Reconnecte-toi d\'abord',
        'auto_caption' => 'Se synchronise automatiquement une fois par jour.',
        'sync_now' => 'Synchroniser maintenant',

        'consent_expired' => 'Consentement expiré — reconnecte-toi.',
        'unavailable' => 'Enable Banking est temporairement indisponible. Réessaie dans un moment.',
        'new_found' => ':count nouvelles transactions trouvées.',
        'none' => 'Aucune nouvelle transaction.',
    ],

    'disconnect' => [
        'heading' => 'Déconnecter l\'open banking ?',
        'body' => 'Cela supprime les identifiants Enable Banking et le consentement stockés. La synchronisation automatique s\'arrête immédiatement. Les transactions déjà importées dans Beatrax ne sont pas touchées.',
        'confirm' => 'Déconnecter',
        'cancel' => 'Rester connecté',
    ],

    'ics' => [
        'section_label' => 'Import de fichier — aucun identifiant stocké',
        'heading' => 'Relevé de carte de crédit ICS',
        'step_login' => 'Connecte-toi',
        'step_download' => 'Télécharge le relevé',
        'pdf_statement' => 'Relevé PDF',
        'step_drop' => 'Dépose-le ci-dessous',
        'drop_zone_label' => 'Dépose ici ton fichier de relevé',
        'drop_zone_hint' => 'ou parcours tes fichiers',
        'browse_aria' => 'Parcourir pour trouver un fichier de relevé ICS',
        'import_button' => 'Importer le relevé',
        'validation' => [
            'required' => 'Dépose le relevé ICS que tu as téléchargé depuis Mijn ICS.',
            'max' => 'Ce fichier est trop volumineux. Les relevés PDF ICS font normalement moins de 1 Mo chacun.',
            'extensions' => 'Ce n\'est pas un PDF. Mijn ICS n\'exporte que des relevés PDF.',
        ],
        'could_not_read' => 'Impossible de lire :filename. L\'erreur complète est dans /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Avant de te connecter à un tiers',
        'body' => 'Activer l\'open banking envoie ton consentement de connexion bancaire, puis tes données de transactions et de soldes, directement de cet appareil vers Enable Banking et ta banque. Beatrax n\'exploite aucun serveur qui voit ces données — mais Enable Banking et ta banque, si. C\'est différent de toutes les autres méthodes d\'import de Beatrax, qui n\'envoient jamais de données nulle part.',
        'acknowledge' => 'Je comprends que mes données de transactions seront partagées avec Enable Banking et ma banque.',
        'confirm' => 'Activer l\'open banking',
        'cancel' => 'Annuler',
    ],

    'wizard' => [
        'heading' => 'Connecte ta banque',
        'intro' => 'Beatrax utilise ta propre application Enable Banking pour que tes identifiants ne passent jamais par un serveur partagé. C\'est une configuration unique par banque.',

        'step1_title' => 'Génère ta paire de clés locale',
        'step1_body' => 'Beatrax génère une paire de clés RSA sur cet appareil. La clé privée ne le quitte jamais.',
        'generate_keypair' => 'Générer la paire de clés',
        'public_key_label' => 'Clé publique',
        'copy_public_key' => 'Copier la clé publique',
        'copied' => 'Copié',
        'redirect_uri_label' => 'URI de redirection',
        'copy_redirect_uri' => 'Copier l\'URI de redirection',

        'step2_title' => 'Enregistre l\'application dans Enable Banking',
        'step2_body' => 'Ouvre le portail développeur Enable Banking, crée une application et colles-y la clé publique et l\'URI de redirection de l\'étape 1.',
        'open_portal' => 'Ouvrir le portail Enable Banking ↗',

        'step3_title' => 'Colle ton identifiant d\'application',
        'application_id_label' => 'Identifiant d\'application',
        'step3_help' => 'Il est stocké dans un fichier local en dehors de la base de données, avec des permissions restrictives, et ne quitte jamais cet appareil.',

        'step4_title' => 'Choisis ta banque',
        'via_enable_banking' => 'via Enable Banking',
        'other_institution' => 'Autre établissement',
        'institution_id_placeholder' => 'Identifiant de l\'établissement',

        'step5_title' => 'Donne ton consentement dans le navigateur',
        'step5_body' => 'Clique ci-dessous pour ouvrir l\'écran de connexion et de consentement de ta banque. Termine la connexion et l\'éventuelle étape à deux facteurs : tu seras ramené ici automatiquement pour finir d\'activer l\'open banking.',

        'cancel' => 'Annuler',
        'continue' => 'Continuer →',
        'continue_to_bank' => 'Continuer vers :bank →',
        'your_bank' => 'ta banque',

        'errors' => [
            'save_keypair_failed' => 'Impossible d\'enregistrer ta paire de clés sur le disque — vérifie les permissions de ton dossier de secrets et réessaie.',
            'generate_failed' => 'Impossible de générer une paire de clés sur cet appareil — vérifie ta configuration OpenSSL.',
            'export_failed' => 'Impossible d\'exporter la paire de clés générée.',
            'read_public_failed' => 'Impossible de lire la clé publique générée.',
            'generate_first' => 'Génère une paire de clés avant de continuer.',
            'paste_application_id' => 'Colle l\'identifiant d\'application du portail Enable Banking avant de continuer.',
            'save_application_id_failed' => 'Impossible d\'enregistrer ton identifiant d\'application sur le disque — vérifie les permissions de ton dossier de secrets et réessaie.',
            'choose_bank' => 'Choisis une banque avant de continuer.',
        ],
    ],

    'alert' => [
        'reconsent' => 'Reconnecte ta banque',
    ],

    'errors' => [
        'wizard_incomplete' => 'Termine d\'abord l\'assistant de configuration Open Banking.',
        'no_bank_chosen' => 'Choisis une banque avant de te connecter.',
        'no_consent_url' => 'Enable Banking n\'a pas renvoyé d\'URL de consentement.',
        'unparseable_consent_url' => 'Enable Banking a renvoyé une URL de consentement illisible.',
        'non_public_consent_host' => 'Enable Banking a renvoyé un hôte de consentement non public.',
        'unsafe_consent_url' => 'Enable Banking a renvoyé une URL de consentement non sûre.',
        'no_authorization_code' => 'Le rappel d\'Enable Banking n\'a renvoyé aucun code d\'autorisation.',
        'no_session_id' => 'Enable Banking n\'a pas renvoyé d\'identifiant de session.',
    ],
];
