<?php

declare(strict_types=1);

return [
    'heading' => 'Boîtes de réception',
    'intro' => 'Connecte des boîtes Gmail et Microsoft 365 pour que Beatrax puisse y chercher des reçus.',

    'connection_canceled' => 'Connexion annulée.',
    'connection_failed' => 'Impossible de finaliser la connexion.',

    'backfilling' => 'Récupération de l\'historique',
    'messages_suffix' => 'messages',

    'connect_heading' => 'Connecte ton e-mail',
    'connect_body' => 'Importe les reçus de PayPal, ICS Cards, Google Play et d\'autres commerçants en donnant à Beatrax un accès en lecture seule à une ou plusieurs de tes boîtes de réception.',
    'connect_gmail' => 'Connecter Gmail',
    'connect_microsoft' => 'Connecter Microsoft 365',
    'readonly_note' => 'Beatrax lit uniquement les messages. Il n\'envoie, n\'étiquette, ne déplace et ne supprime jamais rien dans ta boîte de réception.',

    'months' => ':count mois|:count mois',
    'not_scanned_yet' => 'pas encore analysée',
    'last_scanned' => 'dernière analyse',
    'window_prefix' => 'Période :',
    'edit' => 'Modifier',

    'badge' => [
        'idle' => 'Inactive',
        'backfilling' => 'Récupération',
        'scanning' => 'Analyse',
        'rate_limited' => 'Limite atteinte',
        'needs_reauth' => 'Reconnexion requise',
        'error' => 'Erreur',
    ],

    'retry_seconds' => 'nouvelle tentative dans :ns',
    'retry_minutes' => 'nouvelle tentative dans :nmin',
    'retry_hours' => 'nouvelle tentative dans :nh',

    'reconnect' => 'Reconnecter',
    'disconnect' => 'Déconnecter',
    'scan_now' => 'Analyser maintenant',
    'scan_in_progress_title' => 'Une analyse est déjà en cours',

    'add_another' => 'Ajouter une autre boîte de réception',
    'gmail_card_body' => 'Connecte un compte Gmail pour que Beatrax puisse y chercher des reçus.',
    'microsoft_card_body' => 'Connecte un compte Microsoft 365 ou Outlook.com pour que Beatrax puisse y chercher des reçus.',

    'discovered_heading' => 'Expéditeurs détectés',
    'discovered_body' => 'Des expéditeurs qui ont l\'air d\'envoyer des reçus mais qui ne figurent pas encore sur ta liste de reçus connus. Ajoute ceux que tu veux faire analyser par Beatrax ; ignore les autres.',
    'last_seen' => 'dernière apparition',
    'seen_times' => 'Vu :count fois|Vu :count fois',
    'add' => 'Ajouter',
    'add_aria' => 'Ajouter :email',
    'dismiss' => 'Ignorer',
    'dismiss_aria' => 'Ignorer :email',

    'toast' => [
        'scan_in_progress' => 'Une analyse est déjà en cours.',
        'scan_started' => 'Analyse démarrée.',
        'sender_added' => 'Expéditeur ajouté.',
        'sender_dismissed' => 'Expéditeur ignoré.',
    ],
];
