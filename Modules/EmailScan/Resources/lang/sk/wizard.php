<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Nastav si vlastného OAuth klienta pre Gmail',
    'microsoft_title' => 'Nastav si vlastného OAuth klienta pre Microsoft 365',
    'intro' => 'Beatrax používa tvoj vlastný projekt v Google Cloud alebo registráciu aplikácie v Azure, takže tvoje prihlasovacie údaje sa nikdy nedostanú na zdieľaný server. Toto nastavenie stačí spraviť raz pre každého poskytovateľa.',

    'copied' => 'Skopírované',
    'cancel' => 'Zrušiť',
    'save_connect' => 'Uložiť a pripojiť',

    'secret_help' => 'Ukladá sa zašifrovaný do databázy na tomto zariadení. Beatrax ho posiela len Googlu alebo Microsoftu, aby získal a obnovoval tvoj prístupový token — nikam inam.',

    'gmail' => [
        'step1_title' => 'Otvor Google Cloud Console',
        'step1_body' => 'Otvor Google Cloud Console na novej karte. Prihlás sa účtom Google, ktorý chceš skenovať, a potom vytvor nový projekt (alebo vyber existujúci osobný projekt).',
        'step1_link' => 'Otvoriť Google Cloud Console',
        'step2_title' => 'Zapni Gmail API',
        'step2_body' => 'V novom projekte vyhľadaj v API Library položku "Gmail API" a klikni na Enable. Projekt tým získa možnosť volať Gmail v tvojom mene.',
        'step3_title' => 'Nastav obrazovku súhlasu OAuth',
        'step3_body' => 'Otvor APIs & Services → OAuth consent screen. Ako User type zvoľ "External", ako názov aplikácie zadaj "Beatrax" a ako kontakt podpory aj kontakt vývojára zadaj svoj vlastný e-mail. Pridaj rozsah https://www.googleapis.com/auth/gmail.readonly. Klikni na Save and continue a potom na Back to Dashboard.',
        'step4_title' => 'Prepni obrazovku súhlasu do stavu "In production"',
        'step4_body' => 'Na stránke OAuth consent screen klikni na Publish App a potvrď. Je to nutné — bez toho obnovovacie tokeny, ktoré Beatrax dostane, vypršia po 7 dňoch. Keď si jediný používateľ, publikovanie nevyžaduje žiadnu kontrolu zo strany Google.',
        'step4_checkbox' => 'Obrazovku súhlasu OAuth mám prepnutú do In production',
        'step5_title' => 'Vytvor OAuth Client ID',
        'step5_body' => 'Otvor Credentials → Create Credentials → OAuth Client ID. Ako typ aplikácie zvoľ "Web application". Nastav názov "Beatrax". Do poľa "Authorized redirect URIs" vlož presne URI uvedené nižšie.',
        'step6_title' => 'Vlož svoje client ID a client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Otvor Azure Portal',
        'step1_body' => 'Otvor Microsoft Entra admin center na novej karte. Prihlás sa účtom Microsoft, ktorý chceš skenovať.',
        'step1_link' => 'Otvoriť Azure Portal',
        'step2_title' => 'Zaregistruj novú aplikáciu',
        'step2_body' => 'Otvor App registrations → New registration. Pomenuj ju "Beatrax". V časti "Supported account types" zvoľ "Accounts in any organizational directory and personal Microsoft accounts" (vďaka tomu pripojíš tou istou aplikáciou osobné schránky Outlook.com aj pracovné schránky Microsoft 365).',
        'step3_title' => 'Pridaj redirect URI',
        'step3_body' => 'V tom istom registračnom formulári v časti "Redirect URI" zvoľ platformu "Web" a vlož presne URI uvedené nižšie.',
        'step4_title' => 'Udeľ oprávnenie Mail.Read',
        'step4_body' => 'Otvor API permissions → Add a permission → Microsoft Graph → Delegated permissions. Vyber Mail.Read a offline_access. Klikni na Add permissions. Pri osobnom účte nemusíš udeľovať admin consent.',
        'step5_title' => 'Vytvor client secret',
        'step5_body' => 'Otvor Certificates & secrets → New client secret. Ako popis zadaj "Beatrax" a platnosť nastav na 24 mesiacov. Hodnotu secretu si hneď skopíruj — Azure ju zobrazí len raz.',
        'step6_title' => 'Vlož svoje application (client) ID a secret',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Hodnota client secret',
    ],

    'errors' => [
        'pick_provider' => 'Pred odoslaním vyber poskytovateľa.',
        'microsoft_client_id' => 'Zadaj application (client) ID — UUID v tvare 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Zadaj hodnotu client secret, ktorú ti Azure zobrazil pri jeho vytvorení.',
        'google_client_id' => 'Zadaj Google OAuth client ID končiace na .apps.googleusercontent.com.',
        'google_secret' => 'Zadaj Google OAuth client secret začínajúci na GOCSPX-.',
        'google_published' => 'Potvrď, že obrazovka súhlasu OAuth je prepnutá do stavu „In production“.',
        'write_failed' => 'OAuth klienta sa nepodarilo uložiť — zápis do databázy na tomto zariadení zlyhal. Skús to znova.',
    ],
];
