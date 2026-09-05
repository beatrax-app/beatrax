<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Configure ton client OAuth Gmail',
    'microsoft_title' => 'Configure ton client OAuth Microsoft 365',
    'intro' => 'Beatrax utilise ton propre projet Google Cloud / ta propre inscription d\'application Azure, pour que tes identifiants ne touchent jamais un serveur partagé. C\'est une configuration unique par fournisseur.',

    'copied' => 'Copié',
    'cancel' => 'Annuler',
    'save_connect' => 'Enregistrer et connecter',

    'secret_help' => 'Stocké chiffré dans la base de données de cet appareil. Beatrax ne l\'envoie qu\'à Google ou Microsoft, pour obtenir et renouveler ton jeton d\'accès — nulle part ailleurs.',

    'gmail' => [
        'step1_title' => 'Ouvre la Google Cloud Console',
        'step1_body' => 'Ouvre la Google Cloud Console dans un nouvel onglet. Connecte-toi avec le compte Google que tu veux analyser, puis crée un nouveau projet (ou sélectionne un projet personnel existant).',
        'step1_link' => 'Ouvrir la Google Cloud Console',
        'step2_title' => 'Active l\'API Gmail',
        'step2_body' => 'Dans le nouveau projet, cherche "Gmail API" dans l\'API Library et clique sur Enable. Le projet peut alors appeler Gmail en ton nom.',
        'step3_title' => 'Configure l\'écran de consentement OAuth',
        'step3_body' => 'Ouvre APIs & Services → OAuth consent screen. Choisis le User type "External", saisis "Beatrax" comme nom de l\'application, et ta propre adresse e-mail comme contact d\'assistance et contact développeur. Ajoute le scope https://www.googleapis.com/auth/gmail.readonly. Clique sur Save and continue, puis sur Back to Dashboard.',
        'step4_title' => 'Passe l\'écran de consentement en "In production"',
        'step4_body' => 'Sur la page OAuth consent screen, clique sur Publish App et confirme. C\'est indispensable — sans ça, les refresh tokens que Beatrax reçoit expirent au bout de 7 jours. La publication ne nécessite aucune validation de Google quand tu es le seul utilisateur.',
        'step4_checkbox' => 'J\'ai passé l\'écran de consentement OAuth en In production',
        'step5_title' => 'Crée le Client ID OAuth',
        'step5_body' => 'Ouvre Credentials → Create Credentials → OAuth Client ID. Choisis le type d\'application "Web application". Mets "Beatrax" comme nom. Sous "Authorized redirect URIs", colle l\'URI ci-dessous à l\'identique.',
        'step6_title' => 'Colle ton client ID et ton client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Ouvre le portail Azure',
        'step1_body' => 'Ouvre le Microsoft Entra admin center dans un nouvel onglet. Connecte-toi avec le compte Microsoft que tu veux analyser.',
        'step1_link' => 'Ouvrir le portail Azure',
        'step2_title' => 'Enregistre une nouvelle application',
        'step2_body' => 'Ouvre App registrations → New registration. Nomme-la "Beatrax". Sous "Supported account types", choisis "Accounts in any organizational directory and personal Microsoft accounts" (ça te permet de connecter des boîtes Outlook.com personnelles et des boîtes Microsoft 365 professionnelles avec la même application).',
        'step3_title' => 'Ajoute l\'URI de redirection',
        'step3_body' => 'Dans le même formulaire d\'inscription, sous "Redirect URI", choisis la plateforme "Web" et colle l\'URI ci-dessous à l\'identique.',
        'step4_title' => 'Accorde l\'autorisation Mail.Read',
        'step4_body' => 'Ouvre API permissions → Add a permission → Microsoft Graph → Delegated permissions. Sélectionne Mail.Read et offline_access. Clique sur Add permissions. Pour un compte personnel, aucun consentement administrateur n\'est nécessaire.',
        'step5_title' => 'Crée un client secret',
        'step5_body' => 'Ouvre Certificates & secrets → New client secret. Mets "Beatrax" comme description et une expiration de 24 mois. Copie la valeur du secret immédiatement — Azure ne l\'affiche qu\'une seule fois.',
        'step6_title' => 'Colle ton application (client) ID et ton secret',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret value',
    ],

    'errors' => [
        'pick_provider' => 'Choisis un fournisseur avant de valider.',
        'microsoft_client_id' => 'Saisis l\'application (client) ID — un UUID du type 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Saisis la valeur du client secret qu\'Azure t\'a affichée à la création du secret.',
        'google_client_id' => 'Saisis un client ID OAuth Google se terminant par .apps.googleusercontent.com.',
        'google_secret' => 'Saisis un client secret OAuth Google commençant par GOCSPX-.',
        'google_published' => 'Confirme que tu as passé ton écran de consentement OAuth en \'In production\'.',
        'write_failed' => 'Impossible d\'enregistrer ton client OAuth — l\'écriture dans la base de données de cet appareil a échoué. Réessaie.',
    ],
];
