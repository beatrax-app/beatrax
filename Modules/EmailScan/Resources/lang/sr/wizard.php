<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Podesi sopstveni Gmail OAuth klijent',
    'microsoft_title' => 'Podesi sopstveni Microsoft 365 OAuth klijent',
    'intro' => 'Beatrax koristi tvoj sopstveni Google Cloud projekat / Azure registraciju aplikacije, pa tvoji podaci za prijavu nikada ne dolaze na zajednički server. Ovo je jednokratno podešavanje po provajderu.',

    'copied' => 'Kopirano',
    'cancel' => 'Otkaži',
    'save_connect' => 'Sačuvaj i poveži',

    'secret_help' => 'Čuvaju se u lokalnoj konfiguracionoj datoteci izvan baze podataka, sa restriktivnim dozvolama, i nikada ne napuštaju ovaj uređaj.',

    'gmail' => [
        'step1_title' => 'Otvori Google Cloud Console',
        'step1_body' => 'Otvori Google Cloud Console u novoj kartici. Prijavi se Google nalogom koji želiš da skeniraš, pa napravi novi projekat (ili izaberi postojeći lični projekat).',
        'step1_link' => 'Otvori Google Cloud Console',
        'step2_title' => 'Omogući Gmail API',
        'step2_body' => 'U novom projektu potraži „Gmail API” u API Library i klikni Enable. Time projekat dobija mogućnost da poziva Gmail u tvoje ime.',
        'step3_title' => 'Podesi OAuth ekran saglasnosti',
        'step3_body' => 'Otvori APIs & Services → OAuth consent screen. Za User type izaberi „External”, upiši „Beatrax” kao naziv aplikacije i sopstvenu e-poštu kao kontakt za podršku i kontakt programera. Dodaj opseg https://www.googleapis.com/auth/gmail.readonly. Klikni Save and continue, pa Back to Dashboard.',
        'step4_title' => 'Prebaci ekran saglasnosti u „In production”',
        'step4_body' => 'Na stranici OAuth ekrana saglasnosti klikni Publish App i potvrdi. To je neophodno — bez toga tokeni za osvežavanje koje Beatrax dobija ističu posle 7 dana. Objavljivanje ne zahteva Google proveru kada si jedini korisnik.',
        'step4_checkbox' => 'OAuth ekran saglasnosti je objavljen u In production',
        'step5_title' => 'Napravi OAuth Client ID',
        'step5_body' => 'Otvori Credentials → Create Credentials → OAuth Client ID. Za tip aplikacije izaberi „Web application”. Naziv postavi na „Beatrax”. Pod „Authorized redirect URIs” nalepi tačno dole navedeni URI.',
        'step6_title' => 'Nalepi svoj client ID i client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Otvori Azure Portal',
        'step1_body' => 'Otvori Microsoft Entra admin center u novoj kartici. Prijavi se Microsoft nalogom koji želiš da skeniraš.',
        'step1_link' => 'Otvori Azure Portal',
        'step2_title' => 'Registruj novu aplikaciju',
        'step2_body' => 'Otvori App registrations → New registration. Nazovi je „Beatrax”. Pod „Supported account types” izaberi „Accounts in any organizational directory and personal Microsoft accounts” (tako istom aplikacijom povezuješ i lične Outlook.com i poslovne Microsoft 365 sandučiće).',
        'step3_title' => 'Dodaj redirect URI',
        'step3_body' => 'U istom obrascu registracije, pod „Redirect URI”, izaberi platformu „Web” i nalepi tačno dole navedeni URI.',
        'step4_title' => 'Dodeli dozvolu Mail.Read',
        'step4_body' => 'Otvori API permissions → Add a permission → Microsoft Graph → Delegated permissions. Izaberi Mail.Read i offline_access. Klikni Add permissions. Za lični nalog nije potrebna saglasnost administratora.',
        'step5_title' => 'Napravi client secret',
        'step5_body' => 'Otvori Certificates & secrets → New client secret. Postavi opis „Beatrax” i rok važenja od 24 meseca. Odmah kopiraj vrednost tajne — Azure je prikazuje samo jednom.',
        'step6_title' => 'Nalepi svoj application (client) ID i tajnu',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret value',
    ],

    'errors' => [
        'pick_provider' => 'Izaberi provajdera pre slanja.',
        'microsoft_client_id' => 'Unesi application (client) ID — UUID poput 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Unesi vrednost client secret koju ti je Azure prikazao pri pravljenju tajne.',
        'google_client_id' => 'Unesi Google OAuth client ID koji se završava na .apps.googleusercontent.com.',
        'google_secret' => 'Unesi Google OAuth client secret koji počinje sa GOCSPX-.',
        'google_published' => 'Potvrdi da je OAuth ekran saglasnosti prebačen u „In production”.',
        'write_failed' => 'Nije bilo moguće sačuvati tvoj OAuth klijent na disk — proveri dozvole direktorijuma sa tajnama i pokušaj ponovo.',
    ],
];
