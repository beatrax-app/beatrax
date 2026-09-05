<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Stel je Gmail-OAuth-client in',
    'microsoft_title' => 'Stel je Microsoft 365-OAuth-client in',
    'intro' => 'Beatrax gebruikt je eigen Google Cloud-project / Azure-app-registratie, zodat je gegevens nooit op een gedeelde server terechtkomen. Dit is een eenmalige instelling per provider.',

    'copied' => 'Gekopieerd',
    'cancel' => 'Annuleren',
    'save_connect' => 'Opslaan en verbinden',

    'secret_help' => 'Wordt versleuteld opgeslagen in de database op dit apparaat. Beatrax stuurt hem alleen naar Google of Microsoft, om je toegangstoken op te halen en te vernieuwen — nergens anders heen.',

    'gmail' => [
        'step1_title' => 'Open Google Cloud Console',
        'step1_body' => 'Open de Google Cloud Console in een nieuw tabblad. Meld je aan met het Google-account dat je wilt scannen en maak een nieuw project aan (of selecteer een bestaand persoonlijk project).',
        'step1_link' => 'Open Google Cloud Console',
        'step2_title' => 'Schakel de Gmail API in',
        'step2_body' => 'Zoek in het nieuwe project naar "Gmail API" in de API Library en klik op Enable. Hiermee krijgt het project de mogelijkheid om Gmail namens jou aan te roepen.',
        'step3_title' => 'Configureer het OAuth-toestemmingsscherm',
        'step3_body' => 'Open APIs & Services → OAuth consent screen. Kies User type "External", vul "Beatrax" in als app-naam en je eigen e-mailadres als support- en developer-contact. Voeg de scope https://www.googleapis.com/auth/gmail.readonly toe. Klik op Save and continue en daarna op Back to Dashboard.',
        'step4_title' => 'Zet het toestemmingsscherm op "In production"',
        'step4_body' => 'Klik op de OAuth consent screen-pagina op Publish App en bevestig. Dit is verplicht — zonder dit verlopen de refresh-tokens die Beatrax ontvangt na 7 dagen. Publiceren vereist geen beoordeling door Google als jij de enige gebruiker bent.',
        'step4_checkbox' => 'Ik heb het OAuth-toestemmingsscherm op In production gezet',
        'step5_title' => 'Maak de OAuth Client ID aan',
        'step5_body' => 'Open Credentials → Create Credentials → OAuth Client ID. Kies applicatietype "Web application". Stel de naam in op "Beatrax". Plak de onderstaande URI exact onder "Authorized redirect URIs".',
        'step6_title' => 'Plak je client-ID en client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Open Azure Portal',
        'step1_body' => 'Open het Microsoft Entra admin center in een nieuw tabblad. Meld je aan met het Microsoft-account dat je wilt scannen.',
        'step1_link' => 'Open Azure Portal',
        'step2_title' => 'Registreer een nieuwe applicatie',
        'step2_body' => 'Open App registrations → New registration. Noem het "Beatrax". Kies onder "Supported account types" voor "Accounts in any organizational directory and personal Microsoft accounts" (hiermee kun je met dezelfde app zowel persoonlijke Outlook.com- als zakelijke Microsoft 365-postvakken koppelen).',
        'step3_title' => 'Voeg de redirect-URI toe',
        'step3_body' => 'Kies in hetzelfde registratieformulier onder "Redirect URI" platform "Web" en plak de onderstaande URI exact.',
        'step4_title' => 'Verleen Mail.Read-toestemming',
        'step4_body' => 'Open API permissions → Add a permission → Microsoft Graph → Delegated permissions. Selecteer Mail.Read en offline_access. Klik op Add permissions. Voor een persoonlijk account hoef je geen admin consent te verlenen.',
        'step5_title' => 'Maak een client secret aan',
        'step5_body' => 'Open Certificates & secrets → New client secret. Stel beschrijving "Beatrax" in en een vervaltermijn van 24 maanden. Kopieer de secret-waarde meteen — Azure toont deze slechts één keer.',
        'step6_title' => 'Plak je application (client) ID en secret',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret value',
    ],

    'errors' => [
        'pick_provider' => 'Kies een provider voordat je verzendt.',
        'microsoft_client_id' => 'Vul de application (client) ID in — een UUID zoals 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Vul de client secret-waarde in die Azure toonde toen je de secret aanmaakte.',
        'google_client_id' => 'Vul een Google-OAuth-client-ID in die eindigt op .apps.googleusercontent.com.',
        'google_secret' => 'Vul een Google-OAuth-client-secret in die begint met GOCSPX-.',
        'google_published' => 'Bevestig dat je je OAuth-toestemmingsscherm op \'In production\' hebt gezet.',
        'write_failed' => 'Kon je OAuth-client niet opslaan — het schrijven naar de database op dit apparaat is mislukt. Probeer het opnieuw.',
    ],
];
