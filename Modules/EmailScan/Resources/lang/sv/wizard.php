<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Konfigurera din OAuth-klient för Gmail',
    'microsoft_title' => 'Konfigurera din OAuth-klient för Microsoft 365',
    'intro' => 'Beatrax använder ditt eget Google Cloud-projekt respektive din egen Azure-appregistrering, så att dina uppgifter aldrig hamnar på en delad server. Det här är en engångskonfiguration per leverantör.',

    'copied' => 'Kopierat',
    'cancel' => 'Avbryt',
    'save_connect' => 'Spara och anslut',

    'secret_help' => 'De sparas i en lokal konfigurationsfil utanför databasen med begränsade behörigheter och lämnar aldrig den här enheten.',

    'gmail' => [
        'step1_title' => 'Öppna Google Cloud Console',
        'step1_body' => 'Öppna Google Cloud Console i en ny flik. Logga in med det Google-konto du vill skanna och skapa sedan ett nytt projekt (eller välj ett befintligt personligt projekt).',
        'step1_link' => 'Öppna Google Cloud Console',
        'step2_title' => 'Aktivera Gmail API',
        'step2_body' => 'Sök efter "Gmail API" i API Library i det nya projektet och klicka på Enable. Det ger projektet möjlighet att anropa Gmail åt dig.',
        'step3_title' => 'Konfigurera OAuth-medgivandeskärmen',
        'step3_body' => 'Öppna APIs & Services → OAuth consent screen. Välj User type "External", ange "Beatrax" som appnamn och din egen e-postadress som support- och utvecklarkontakt. Lägg till scopet https://www.googleapis.com/auth/gmail.readonly. Klicka på Save and continue och sedan på Back to Dashboard.',
        'step4_title' => 'Sätt medgivandeskärmen till "In production"',
        'step4_body' => 'Klicka på Publish App på sidan OAuth consent screen och bekräfta. Det krävs — utan det slutar de uppdateringstoken som Beatrax får att gälla efter 7 dagar. Publiceringen kräver ingen granskning av Google när du är den enda användaren.',
        'step4_checkbox' => 'Jag har satt OAuth-medgivandeskärmen till In production',
        'step5_title' => 'Skapa OAuth-klient-ID',
        'step5_body' => 'Öppna Credentials → Create Credentials → OAuth Client ID. Välj applikationstypen "Web application". Ange namnet "Beatrax". Klistra in adressen nedan exakt under "Authorized redirect URIs".',
        'step6_title' => 'Klistra in ditt klient-ID och din klienthemlighet',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Öppna Azure Portal',
        'step1_body' => 'Öppna Microsoft Entra admin center i en ny flik. Logga in med det Microsoft-konto du vill skanna.',
        'step1_link' => 'Öppna Azure Portal',
        'step2_title' => 'Registrera en ny applikation',
        'step2_body' => 'Öppna App registrations → New registration. Ge den namnet "Beatrax". Välj "Accounts in any organizational directory and personal Microsoft accounts" under "Supported account types" (då kan du ansluta både personliga Outlook.com-inkorgar och Microsoft 365-inkorgar från jobbet med samma app).',
        'step3_title' => 'Lägg till omdirigeringsadressen',
        'step3_body' => 'Välj plattformen "Web" under "Redirect URI" i samma registreringsformulär och klistra in adressen nedan exakt.',
        'step4_title' => 'Bevilja behörigheten Mail.Read',
        'step4_body' => 'Öppna API permissions → Add a permission → Microsoft Graph → Delegated permissions. Välj Mail.Read och offline_access. Klicka på Add permissions. Du behöver inte ge administratörsmedgivande för ett personligt konto.',
        'step5_title' => 'Skapa en klienthemlighet',
        'step5_body' => 'Öppna Certificates & secrets → New client secret. Ange beskrivningen "Beatrax" och en giltighetstid på 24 månader. Kopiera hemlighetens värde direkt — Azure visar det bara en gång.',
        'step6_title' => 'Klistra in ditt application (client) ID och din hemlighet',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret value',
    ],

    'errors' => [
        'pick_provider' => 'Välj en leverantör innan du skickar.',
        'microsoft_client_id' => 'Ange ditt application (client) ID — ett UUID som 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Ange det värde för klienthemligheten som Azure visade när du skapade hemligheten.',
        'google_client_id' => 'Ange ett Google OAuth-klient-ID som slutar på .apps.googleusercontent.com.',
        'google_secret' => 'Ange en Google OAuth-klienthemlighet som börjar med GOCSPX-.',
        'google_published' => 'Bekräfta att du har satt din OAuth-medgivandeskärm till "In production".',
        'write_failed' => 'Din OAuth-klient kunde inte sparas till disk — kontrollera behörigheterna för din secrets-katalog och försök igen.',
    ],
];
