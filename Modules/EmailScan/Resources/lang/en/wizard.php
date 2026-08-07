<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Set up your Gmail OAuth client',
    'microsoft_title' => 'Set up your Microsoft 365 OAuth client',
    'intro' => 'beatrax uses your own Google Cloud project / Azure app registration so your credentials never touch a shared server. This is a one-time setup per provider.',

    'copied' => 'Copied',
    'cancel' => 'Cancel',
    'save_connect' => 'Save and connect',

    'secret_help' => 'These are stored in a local config file outside the database with restrictive permissions and never leave this device.',

    'gmail' => [
        'step1_title' => 'Open Google Cloud Console',
        'step1_body' => 'Open the Google Cloud Console in a new tab. Sign in with the Google account you want to scan, then create a new project (or select an existing personal project).',
        'step1_link' => 'Open Google Cloud Console',
        'step2_title' => 'Enable the Gmail API',
        'step2_body' => 'In the new project, search for "Gmail API" in the API Library and click Enable. This grants the project the ability to call Gmail on your behalf.',
        'step3_title' => 'Configure the OAuth consent screen',
        'step3_body' => 'Open APIs & Services → OAuth consent screen. Choose User type "External", enter "beatrax" as the app name, and your own email as the support contact and developer contact. Add the scope https://www.googleapis.com/auth/gmail.readonly. Click Save and continue, then Back to Dashboard.',
        'step4_title' => 'Push the consent screen to "In production"',
        'step4_body' => 'On the OAuth consent screen page, click Publish App and confirm. This is required — without it, the refresh tokens beatrax receives expire after 7 days. Publishing requires no Google review when the only user is you.',
        'step4_checkbox' => "I've published the OAuth consent screen to In production",
        'step5_title' => 'Create the OAuth Client ID',
        'step5_body' => 'Open Credentials → Create Credentials → OAuth Client ID. Choose application type "Web application". Set name "beatrax". Under "Authorized redirect URIs" paste the URI below exactly.',
        'step6_title' => 'Paste your client ID and client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Open Azure Portal',
        'step1_body' => 'Open the Microsoft Entra admin center in a new tab. Sign in with the Microsoft account you want to scan.',
        'step1_link' => 'Open Azure Portal',
        'step2_title' => 'Register a new application',
        'step2_body' => 'Open App registrations → New registration. Name it "beatrax". Under "Supported account types" choose "Accounts in any organizational directory and personal Microsoft accounts" (this lets you connect personal Outlook.com and work Microsoft 365 inboxes with the same app).',
        'step3_title' => 'Add the redirect URI',
        'step3_body' => 'In the same registration form, under "Redirect URI", choose platform "Web" and paste the URI below exactly.',
        'step4_title' => 'Grant Mail.Read permission',
        'step4_body' => 'Open API permissions → Add a permission → Microsoft Graph → Delegated permissions. Select Mail.Read and offline_access. Click Add permissions. You do not need to grant admin consent for a personal account.',
        'step5_title' => 'Create a client secret',
        'step5_body' => 'Open Certificates & secrets → New client secret. Set description "beatrax" and an expiry of 24 months. Copy the secret value immediately — Azure shows it only once.',
        'step6_title' => 'Paste your application (client) ID and secret',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret value',
    ],

    'errors' => [
        'pick_provider' => 'Pick a provider before submitting.',
        'microsoft_client_id' => 'Enter the application (client) ID — a UUID like 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Enter the client secret value Azure showed you when you created the secret.',
        'google_client_id' => 'Enter a Google OAuth client ID ending in .apps.googleusercontent.com.',
        'google_secret' => 'Enter a Google OAuth client secret starting with GOCSPX-.',
        'google_published' => "Confirm that you've pushed your OAuth consent screen to 'In production'.",
        'write_failed' => 'Could not save your OAuth client to disk — check your secrets-directory permissions and try again.',
    ],
];
