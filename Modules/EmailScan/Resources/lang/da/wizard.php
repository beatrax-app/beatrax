<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Opsæt din OAuth-klient til Gmail',
    'microsoft_title' => 'Opsæt din OAuth-klient til Microsoft 365',
    'intro' => 'Beatrax bruger dit eget Google Cloud-projekt eller din egen Azure-appregistrering, så dine oplysninger aldrig havner på en delt server. Det er en engangsopsætning pr. udbyder.',

    'copied' => 'Kopieret',
    'cancel' => 'Annullér',
    'save_connect' => 'Gem og forbind',

    'secret_help' => 'Gemmes krypteret i databasen på denne enhed. Beatrax sender den kun til Google eller Microsoft for at hente og forny dit adgangstoken — ingen andre steder hen.',

    'gmail' => [
        'step1_title' => 'Åbn Google Cloud Console',
        'step1_body' => 'Åbn Google Cloud Console i en ny fane. Log ind med den Google-konto, du vil scanne, og opret derefter et nyt projekt (eller vælg et eksisterende personligt projekt).',
        'step1_link' => 'Åbn Google Cloud Console',
        'step2_title' => 'Aktivér Gmail API',
        'step2_body' => 'Søg efter "Gmail API" i API Library i det nye projekt, og klik på Enable. Det giver projektet mulighed for at kalde Gmail på dine vegne.',
        'step3_title' => 'Konfigurér OAuth-samtykkeskærmen',
        'step3_body' => 'Åbn APIs & Services → OAuth consent screen. Vælg User type "External", angiv "Beatrax" som appnavn og din egen e-mailadresse som support- og udviklerkontakt. Tilføj scopet https://www.googleapis.com/auth/gmail.readonly. Klik på Save and continue og derefter på Back to Dashboard.',
        'step4_title' => 'Sæt samtykkeskærmen til "In production"',
        'step4_body' => 'Klik på Publish App på siden OAuth consent screen, og bekræft. Det er nødvendigt — ellers udløber de refresh-tokens, Beatrax modtager, efter 7 dage. Udgivelsen kræver ingen gennemgang fra Google, når du er den eneste bruger.',
        'step4_checkbox' => 'Jeg har sat OAuth-samtykkeskærmen til In production',
        'step5_title' => 'Opret OAuth-klient-id',
        'step5_body' => 'Åbn Credentials → Create Credentials → OAuth Client ID. Vælg applikationstypen "Web application". Angiv navnet "Beatrax". Indsæt adressen nedenfor nøjagtigt under "Authorized redirect URIs".',
        'step6_title' => 'Indsæt dit klient-id og din klienthemmelighed',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Åbn Azure Portal',
        'step1_body' => 'Åbn Microsoft Entra admin center i en ny fane. Log ind med den Microsoft-konto, du vil scanne.',
        'step1_link' => 'Åbn Azure Portal',
        'step2_title' => 'Registrér en ny applikation',
        'step2_body' => 'Åbn App registrations → New registration. Kald den "Beatrax". Vælg "Accounts in any organizational directory and personal Microsoft accounts" under "Supported account types" (så kan du forbinde både personlige Outlook.com-indbakker og Microsoft 365-indbakker fra arbejdet med den samme app).',
        'step3_title' => 'Tilføj omdirigeringsadressen',
        'step3_body' => 'Vælg platformen "Web" under "Redirect URI" i den samme registreringsformular, og indsæt adressen nedenfor nøjagtigt.',
        'step4_title' => 'Giv tilladelsen Mail.Read',
        'step4_body' => 'Åbn API permissions → Add a permission → Microsoft Graph → Delegated permissions. Vælg Mail.Read og offline_access. Klik på Add permissions. Du behøver ikke at give administratorsamtykke for en personlig konto.',
        'step5_title' => 'Opret en klienthemmelighed',
        'step5_body' => 'Åbn Certificates & secrets → New client secret. Angiv beskrivelsen "Beatrax" og en gyldighed på 24 måneder. Kopiér hemmelighedens værdi med det samme — Azure viser den kun én gang.',
        'step6_title' => 'Indsæt dit application (client) ID og din hemmelighed',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret value',
    ],

    'errors' => [
        'pick_provider' => 'Vælg en udbyder, før du sender.',
        'microsoft_client_id' => 'Angiv dit application (client) ID — en UUID som 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Angiv den værdi for klienthemmeligheden, som Azure viste dig, da du oprettede hemmeligheden.',
        'google_client_id' => 'Angiv et Google OAuth-klient-id, der slutter på .apps.googleusercontent.com.',
        'google_secret' => 'Angiv en Google OAuth-klienthemmelighed, der begynder med GOCSPX-.',
        'google_published' => 'Bekræft, at du har sat din OAuth-samtykkeskærm til "In production".',
        'write_failed' => 'Din OAuth-klient kunne ikke gemmes — skrivningen til databasen på denne enhed mislykkedes. Prøv igen.',
    ],
];
