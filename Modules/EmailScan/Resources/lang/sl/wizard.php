<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Nastavi svoj odjemalec OAuth za Gmail',
    'microsoft_title' => 'Nastavi svoj odjemalec OAuth za Microsoft 365',
    'intro' => 'Beatrax uporablja tvoj lastni projekt Google Cloud / registracijo aplikacije Azure, zato tvoji prijavni podatki nikoli ne pridejo na skupni strežnik. To je enkratna nastavitev za vsakega ponudnika.',

    'copied' => 'Kopirano',
    'cancel' => 'Prekliči',
    'save_connect' => 'Shrani in poveži',

    'secret_help' => 'Shranjeni so v lokalni konfiguracijski datoteki zunaj zbirke podatkov, z omejenimi dovoljenji, in nikoli ne zapustijo te naprave.',

    'gmail' => [
        'step1_title' => 'Odpri Google Cloud Console',
        'step1_body' => 'Odpri Google Cloud Console v novem zavihku. Prijavi se z Google računom, ki ga želiš pregledovati, nato ustvari nov projekt (ali izberi obstoječi osebni projekt).',
        'step1_link' => 'Odpri Google Cloud Console',
        'step2_title' => 'Omogoči Gmail API',
        'step2_body' => 'V novem projektu poišči „Gmail API“ v API Library in klikni Enable. S tem projekt dobi možnost, da v tvojem imenu kliče Gmail.',
        'step3_title' => 'Nastavi zaslon za privolitev OAuth',
        'step3_body' => 'Odpri APIs & Services → OAuth consent screen. Za User type izberi „External“, vpiši „Beatrax“ kot ime aplikacije in svoj e-poštni naslov kot kontakt za podporo in kontakt razvijalca. Dodaj obseg https://www.googleapis.com/auth/gmail.readonly. Klikni Save and continue, nato Back to Dashboard.',
        'step4_title' => 'Prestavi zaslon za privolitev v „In production“',
        'step4_body' => 'Na strani zaslona za privolitev OAuth klikni Publish App in potrdi. To je nujno — brez tega žetoni za osvežitev, ki jih prejme Beatrax, potečejo po 7 dneh. Objava ne zahteva Googlovega pregleda, kadar si edini uporabnik.',
        'step4_checkbox' => 'Zaslon za privolitev OAuth je objavljen v In production',
        'step5_title' => 'Ustvari OAuth Client ID',
        'step5_body' => 'Odpri Credentials → Create Credentials → OAuth Client ID. Za vrsto aplikacije izberi „Web application“. Ime nastavi na „Beatrax“. Pod „Authorized redirect URIs“ natančno prilepi spodnji URI.',
        'step6_title' => 'Prilepi svoj client ID in client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Odpri Azure Portal',
        'step1_body' => 'Odpri Microsoft Entra admin center v novem zavihku. Prijavi se z Microsoftovim računom, ki ga želiš pregledovati.',
        'step1_link' => 'Odpri Azure Portal',
        'step2_title' => 'Registriraj novo aplikacijo',
        'step2_body' => 'Odpri App registrations → New registration. Poimenuj jo „Beatrax“. Pod „Supported account types“ izberi „Accounts in any organizational directory and personal Microsoft accounts“ (tako z isto aplikacijo povežeš osebne nabiralnike Outlook.com in službene Microsoft 365).',
        'step3_title' => 'Dodaj redirect URI',
        'step3_body' => 'V istem obrazcu za registracijo pod „Redirect URI“ izberi platformo „Web“ in natančno prilepi spodnji URI.',
        'step4_title' => 'Dodeli dovoljenje Mail.Read',
        'step4_body' => 'Odpri API permissions → Add a permission → Microsoft Graph → Delegated permissions. Izberi Mail.Read in offline_access. Klikni Add permissions. Za osebni račun soglasje skrbnika ni potrebno.',
        'step5_title' => 'Ustvari client secret',
        'step5_body' => 'Odpri Certificates & secrets → New client secret. Nastavi opis „Beatrax“ in veljavnost 24 mesecev. Vrednost skrivnosti takoj kopiraj — Azure jo pokaže samo enkrat.',
        'step6_title' => 'Prilepi svoj application (client) ID in skrivnost',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret value',
    ],

    'errors' => [
        'pick_provider' => 'Pred pošiljanjem izberi ponudnika.',
        'microsoft_client_id' => 'Vnesi application (client) ID — UUID, na primer 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Vnesi vrednost client secret, ki ti jo je Azure pokazal ob ustvarjanju skrivnosti.',
        'google_client_id' => 'Vnesi Googlov OAuth client ID, ki se konča z .apps.googleusercontent.com.',
        'google_secret' => 'Vnesi Googlov OAuth client secret, ki se začne z GOCSPX-.',
        'google_published' => 'Potrdi, da je zaslon za privolitev OAuth prestavljen v „In production“.',
        'write_failed' => 'Odjemalca OAuth ni bilo mogoče shraniti na disk — preveri dovoljenja mape s skrivnostmi in poskusi znova.',
    ],
];
