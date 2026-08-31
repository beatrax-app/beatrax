<?php

declare(strict_types=1);

return [
    'heading' => 'Boîtes de réception',
    'intro' => 'Connecte des boîtes Gmail et Microsoft 365 pour que Beatrax puisse y chercher des reçus.',
    'intro_phone' => "L'analyse des boîtes de réception se fait dans l'application de bureau, pas sur ce téléphone.",

    'phone_heading' => "Ce téléphone n'analyse aucune boîte de réception",
    'phone_body' => "Connecte Gmail ou Microsoft 365 dans l'application de bureau — les reçus qu'elle trouve arrivent ici par synchronisation.",
    'connection_canceled' => 'Connexion annulée.',
    'connection_failed' => 'Impossible de finaliser la connexion.',

    'backfilling' => 'Récupération de l\'historique',
    'backfill_progress' => ':fetched / ~:count message|:fetched / ~:count messages',

    'connect_heading' => 'Connecte ton e-mail',
    'connect_body' => 'Importe les reçus de PayPal, ICS Cards, Google Play et d\'autres commerçants en donnant à Beatrax un accès en lecture seule à une ou plusieurs de tes boîtes de réception.',
    'connect_body_phone' => "Les reçus de PayPal, ICS Cards, Google Play et d'autres commerçants sont importés par l'application de bureau, depuis les boîtes auxquelles tu lui donnes un accès en lecture seule. Ce téléphone montre ce que cet import trouve.",
    'connect_gmail' => 'Connecter Gmail',
    'connect_microsoft' => 'Connecter Microsoft 365',
    'readonly_note' => 'Beatrax lit uniquement les messages. Il n\'envoie, n\'étiquette, ne déplace et ne supprime jamais rien dans ta boîte de réception.',

    'months' => ':count mois|:count mois',
    'not_scanned_yet' => 'pas encore analysée',
    'not_scanned_yet_phone' => 'pas analysée sur ce téléphone',
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

    'error_detail' => "La dernière analyse ne s'est pas terminée. Essayez « Analyser maintenant » ou reconnectez cette boîte.",
    'oauth_no_code' => "Ton fournisseur de messagerie t'a renvoyé sans le code dont Beatrax a besoin pour terminer : aucune boîte n'a été connectée. Recommence la connexion.",
    'oauth_grant_refused' => "Ton fournisseur de messagerie a refusé l'autorisation accordée à Beatrax — elle a expiré ou a été retirée. Recommence la connexion et accorde-la.",
    'oauth_exchange_failed' => "Ton fournisseur de messagerie n'a pas terminé la connexion : aucune boîte n'a été ajoutée. Réessaie dans quelques minutes.",
    'oauth_not_saved' => "La connexion n'a pas pu être enregistrée sur cet appareil : aucune boîte n'a été ajoutée. Réessaie — si l'échec persiste, le journal de l'app note ce qui l'a arrêtée.",
    'oauth_no_offline_access_google' => "Google n'a pas accordé l'autorisation durable dont Beatrax a besoin : cette boîte cesserait d'être analysée dans l'heure. Publie ton écran de consentement OAuth en production, puis reconnecte-la.",
    'oauth_no_offline_access' => "Ton fournisseur de messagerie n'a pas accordé l'autorisation durable dont Beatrax a besoin : cette boîte cesserait d'être analysée dans l'heure. Reconnecte-la et autorise l'accès hors ligne quand on te le demande.",
    'oauth_no_offline_access_google_phone' => "Google n'a pas accordé l'autorisation durable dont Beatrax a besoin : aucune boîte n'a été connectée. Publie ton écran de consentement OAuth en production, puis reconnecte-la — l'analyse elle-même se fait dans l'application de bureau.",
    'oauth_no_offline_access_phone' => "Ton fournisseur de messagerie n'a pas accordé l'autorisation durable dont Beatrax a besoin : aucune boîte n'a été connectée. Reconnecte-la et autorise l'accès hors ligne quand on te le demande — l'analyse elle-même se fait dans l'application de bureau.",

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
    'gmail_card_body_phone' => "Gmail est analysé par l'application de bureau. Un compte connecté ici n'est jamais analysé tout seul.",
    'microsoft_card_body_phone' => "Microsoft 365 et Outlook.com sont analysés par l'application de bureau. Un compte connecté ici n'est jamais analysé tout seul.",

    'discovered_heading' => 'Expéditeurs détectés',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (relevés)',
    ],
    'discovered_body' => 'Des expéditeurs qui ont l\'air d\'envoyer des reçus mais qui ne figurent pas encore sur ta liste de reçus connus. Ajoute ceux que tu veux faire analyser par Beatrax ; ignore les autres.',
    'last_seen' => 'dernière apparition',
    'seen_times' => 'Vu :count fois|Vu :count fois',
    'add' => 'Ajouter',
    'add_aria' => 'Ajouter :email',
    'dismiss' => 'Ignorer',
    'dismiss_aria' => 'Ignorer :email',

    'toast' => [
        'reconnect_first' => 'Reconnectez cette boîte de réception avant l\'analyse.',
        'scan_in_progress' => 'Une analyse est déjà en cours.',
        'scan_started' => 'Analyse démarrée.',
        'sender_added' => 'Expéditeur ajouté.',
        'sender_dismissed' => 'Expéditeur ignoré.',
    ],
];
