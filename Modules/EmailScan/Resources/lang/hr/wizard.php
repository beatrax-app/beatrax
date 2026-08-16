<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Postavi vlastiti Gmail OAuth klijent',
    'microsoft_title' => 'Postavi vlastiti Microsoft 365 OAuth klijent',
    'intro' => 'Beatrax koristi tvoj vlastiti Google Cloud projekt / Azure registraciju aplikacije pa tvoje vjerodajnice nikad ne dolaze na zajednički poslužitelj. Ovo je jednokratna postava po pružatelju.',

    'copied' => 'Kopirano',
    'cancel' => 'Odustani',
    'save_connect' => 'Spremi i poveži',

    'secret_help' => 'Pohranjuju se u lokalnoj konfiguracijskoj datoteci izvan baze podataka, s restriktivnim dozvolama, i nikad ne napuštaju ovaj uređaj.',

    'gmail' => [
        'step1_title' => 'Otvori Google Cloud Console',
        'step1_body' => 'Otvori Google Cloud Console u novoj kartici. Prijavi se Google računom koji želiš skenirati, zatim stvori novi projekt (ili odaberi postojeći osobni projekt).',
        'step1_link' => 'Otvori Google Cloud Console',
        'step2_title' => 'Omogući Gmail API',
        'step2_body' => 'U novom projektu potraži „Gmail API” u API Library i klikni Enable. Time projekt dobiva mogućnost pozivanja Gmaila u tvoje ime.',
        'step3_title' => 'Konfiguriraj OAuth zaslon privole',
        'step3_body' => 'Otvori APIs & Services → OAuth consent screen. Za User type odaberi „External”, upiši „Beatrax” kao naziv aplikacije te vlastitu e-poštu kao kontakt za podršku i kontakt razvijatelja. Dodaj opseg https://www.googleapis.com/auth/gmail.readonly. Klikni Save and continue, zatim Back to Dashboard.',
        'step4_title' => 'Prebaci zaslon privole u „In production”',
        'step4_body' => 'Na stranici OAuth zaslona privole klikni Publish App i potvrdi. To je nužno — bez toga tokeni za osvježavanje koje Beatrax prima istječu nakon 7 dana. Objava ne zahtijeva Googleovu provjeru kad si jedini korisnik.',
        'step4_checkbox' => 'OAuth zaslon privole objavljen je u In production',
        'step5_title' => 'Stvori OAuth Client ID',
        'step5_body' => 'Otvori Credentials → Create Credentials → OAuth Client ID. Za tip aplikacije odaberi „Web application”. Naziv postavi na „Beatrax”. Pod „Authorized redirect URIs” zalijepi točno donji URI.',
        'step6_title' => 'Zalijepi svoj client ID i client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Otvori Azure Portal',
        'step1_body' => 'Otvori Microsoft Entra admin center u novoj kartici. Prijavi se Microsoft računom koji želiš skenirati.',
        'step1_link' => 'Otvori Azure Portal',
        'step2_title' => 'Registriraj novu aplikaciju',
        'step2_body' => 'Otvori App registrations → New registration. Nazovi je „Beatrax”. Pod „Supported account types” odaberi „Accounts in any organizational directory and personal Microsoft accounts” (tako istom aplikacijom možeš povezati osobne Outlook.com i poslovne Microsoft 365 pretince).',
        'step3_title' => 'Dodaj redirect URI',
        'step3_body' => 'U istom obrascu registracije, pod „Redirect URI”, odaberi platformu „Web” i zalijepi točno donji URI.',
        'step4_title' => 'Dodijeli dozvolu Mail.Read',
        'step4_body' => 'Otvori API permissions → Add a permission → Microsoft Graph → Delegated permissions. Odaberi Mail.Read i offline_access. Klikni Add permissions. Za osobni račun ne treba administratorska privola.',
        'step5_title' => 'Stvori client secret',
        'step5_body' => 'Otvori Certificates & secrets → New client secret. Postavi opis „Beatrax” i istek na 24 mjeseca. Odmah kopiraj vrijednost tajne — Azure je prikazuje samo jednom.',
        'step6_title' => 'Zalijepi svoj application (client) ID i tajnu',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret value',
    ],

    'errors' => [
        'pick_provider' => 'Odaberi pružatelja prije slanja.',
        'microsoft_client_id' => 'Unesi application (client) ID — UUID poput 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Unesi vrijednost client secreta koju ti je Azure prikazao pri stvaranju tajne.',
        'google_client_id' => 'Unesi Google OAuth client ID koji završava na .apps.googleusercontent.com.',
        'google_secret' => 'Unesi Google OAuth client secret koji počinje s GOCSPX-.',
        'google_published' => 'Potvrdi da je OAuth zaslon privole prebačen u „In production”.',
        'write_failed' => 'Nije bilo moguće spremiti tvoj OAuth klijent na disk — provjeri dozvole direktorija s tajnama i pokušaj ponovno.',
    ],
];
