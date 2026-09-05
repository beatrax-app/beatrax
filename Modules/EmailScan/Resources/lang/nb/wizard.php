<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Sett opp OAuth-klienten din for Gmail',
    'microsoft_title' => 'Sett opp OAuth-klienten din for Microsoft 365',
    'intro' => 'Beatrax bruker ditt eget Google Cloud-prosjekt eller din egen Azure-appregistrering, slik at opplysningene dine aldri havner på en delt server. Dette er et engangsoppsett per leverandør.',

    'copied' => 'Kopiert',
    'cancel' => 'Avbryt',
    'save_connect' => 'Lagre og koble til',

    'secret_help' => 'Lagres kryptert i databasen på denne enheten. Beatrax sender den bare til Google eller Microsoft for å hente og fornye tilgangstokenet ditt — ingen andre steder.',

    'gmail' => [
        'step1_title' => 'Åpne Google Cloud Console',
        'step1_body' => 'Åpne Google Cloud Console i en ny fane. Logg inn med Google-kontoen du vil skanne, og opprett deretter et nytt prosjekt (eller velg et eksisterende personlig prosjekt).',
        'step1_link' => 'Åpne Google Cloud Console',
        'step2_title' => 'Aktiver Gmail API',
        'step2_body' => 'Søk etter "Gmail API" i API Library i det nye prosjektet, og klikk på Enable. Det gir prosjektet mulighet til å kalle Gmail på dine vegne.',
        'step3_title' => 'Konfigurer OAuth-samtykkeskjermen',
        'step3_body' => 'Åpne APIs & Services → OAuth consent screen. Velg User type "External", skriv inn "Beatrax" som appnavn og din egen e-postadresse som support- og utviklerkontakt. Legg til scopet https://www.googleapis.com/auth/gmail.readonly. Klikk på Save and continue og deretter på Back to Dashboard.',
        'step4_title' => 'Sett samtykkeskjermen til "In production"',
        'step4_body' => 'Klikk på Publish App på siden OAuth consent screen, og bekreft. Det er nødvendig — ellers utløper oppdateringstokenene Beatrax mottar, etter 7 dager. Publisering krever ingen gjennomgang fra Google når du er den eneste brukeren.',
        'step4_checkbox' => 'Jeg har satt OAuth-samtykkeskjermen til In production',
        'step5_title' => 'Opprett OAuth-klient-ID',
        'step5_body' => 'Åpne Credentials → Create Credentials → OAuth Client ID. Velg applikasjonstypen "Web application". Sett navnet til "Beatrax". Lim inn adressen nedenfor nøyaktig under "Authorized redirect URIs".',
        'step6_title' => 'Lim inn klient-ID-en og klienthemmeligheten din',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Åpne Azure Portal',
        'step1_body' => 'Åpne Microsoft Entra admin center i en ny fane. Logg inn med Microsoft-kontoen du vil skanne.',
        'step1_link' => 'Åpne Azure Portal',
        'step2_title' => 'Registrer en ny applikasjon',
        'step2_body' => 'Åpne App registrations → New registration. Kall den "Beatrax". Velg "Accounts in any organizational directory and personal Microsoft accounts" under "Supported account types" (da kan du koble til både personlige Outlook.com-innbokser og Microsoft 365-innbokser fra jobben med den samme appen).',
        'step3_title' => 'Legg til omdirigeringsadressen',
        'step3_body' => 'Velg plattformen "Web" under "Redirect URI" i det samme registreringsskjemaet, og lim inn adressen nedenfor nøyaktig.',
        'step4_title' => 'Gi tillatelsen Mail.Read',
        'step4_body' => 'Åpne API permissions → Add a permission → Microsoft Graph → Delegated permissions. Velg Mail.Read og offline_access. Klikk på Add permissions. Du trenger ikke å gi administratorsamtykke for en personlig konto.',
        'step5_title' => 'Opprett en klienthemmelighet',
        'step5_body' => 'Åpne Certificates & secrets → New client secret. Sett beskrivelsen til "Beatrax" og en gyldighet på 24 måneder. Kopier verdien av hemmeligheten med en gang — Azure viser den bare én gang.',
        'step6_title' => 'Lim inn application (client) ID og hemmeligheten din',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret value',
    ],

    'errors' => [
        'pick_provider' => 'Velg en leverandør før du sender inn.',
        'microsoft_client_id' => 'Skriv inn application (client) ID — en UUID som 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Skriv inn verdien for klienthemmeligheten som Azure viste deg da du opprettet hemmeligheten.',
        'google_client_id' => 'Skriv inn en Google OAuth-klient-ID som slutter på .apps.googleusercontent.com.',
        'google_secret' => 'Skriv inn en Google OAuth-klienthemmelighet som begynner med GOCSPX-.',
        'google_published' => 'Bekreft at du har satt OAuth-samtykkeskjermen din til "In production".',
        'write_failed' => 'OAuth-klienten din kunne ikke lagres — skrivingen til databasen på denne enheten mislyktes. Prøv igjen.',
    ],
];
