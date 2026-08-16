<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Nastav si vlastního OAuth klienta pro Gmail',
    'microsoft_title' => 'Nastav si vlastního OAuth klienta pro Microsoft 365',
    'intro' => 'Beatrax používá tvůj vlastní projekt Google Cloud / registraci aplikace v Azure, takže tvoje přihlašovací údaje nikdy neprojdou sdíleným serverem. Je to jednorázové nastavení pro každého poskytovatele.',

    'copied' => 'Zkopírováno',
    'cancel' => 'Zrušit',
    'save_connect' => 'Uložit a připojit',

    'secret_help' => 'Ukládají se s omezenými oprávněními do lokálního konfiguračního souboru mimo databázi a nikdy neopustí toto zařízení.',

    'gmail' => [
        'step1_title' => 'Otevři Google Cloud Console',
        'step1_body' => 'Otevři Google Cloud Console na nové kartě. Přihlas se účtem Google, který chceš skenovat, a pak vytvoř nový projekt (nebo vyber existující osobní projekt).',
        'step1_link' => 'Otevřít Google Cloud Console',
        'step2_title' => 'Zapni Gmail API',
        'step2_body' => 'V novém projektu vyhledej v API Library „Gmail API“ a klikni na Enable. Tím projekt dostane možnost volat Gmail tvým jménem.',
        'step3_title' => 'Nastav obrazovku souhlasu OAuth',
        'step3_body' => 'Otevři APIs & Services → OAuth consent screen. Zvol User type „External“, jako název aplikace zadej „Beatrax“ a svůj vlastní e-mail jako kontakt podpory i vývojáře. Přidej rozsah https://www.googleapis.com/auth/gmail.readonly. Klikni na Save and continue a pak na Back to Dashboard.',
        'step4_title' => 'Přepni obrazovku souhlasu do „In production“',
        'step4_body' => 'Na stránce OAuth consent screen klikni na Publish App a potvrď. Je to nutné — bez toho refresh tokeny, které Beatrax dostane, vyprší po 7 dnech. Když jsi jediný uživatel, publikování nevyžaduje žádnou kontrolu ze strany Google.',
        'step4_checkbox' => 'Obrazovku souhlasu OAuth mám publikovanou v In production',
        'step5_title' => 'Vytvoř OAuth Client ID',
        'step5_body' => 'Otevři Credentials → Create Credentials → OAuth Client ID. Zvol typ aplikace „Web application“. Nastav název „Beatrax“. Do „Authorized redirect URIs“ vlož přesně URI níže.',
        'step6_title' => 'Vlož své client ID a client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Otevři Azure Portal',
        'step1_body' => 'Otevři Microsoft Entra admin center na nové kartě. Přihlas se účtem Microsoft, který chceš skenovat.',
        'step1_link' => 'Otevřít Azure Portal',
        'step2_title' => 'Zaregistruj novou aplikaci',
        'step2_body' => 'Otevři App registrations → New registration. Pojmenuj ji „Beatrax“. V „Supported account types“ zvol „Accounts in any organizational directory and personal Microsoft accounts“ (díky tomu můžeš stejnou aplikací připojit osobní schránky Outlook.com i pracovní Microsoft 365).',
        'step3_title' => 'Přidej redirect URI',
        'step3_body' => 'Ve stejném registračním formuláři zvol v „Redirect URI“ platformu „Web“ a vlož přesně URI níže.',
        'step4_title' => 'Uděl oprávnění Mail.Read',
        'step4_body' => 'Otevři API permissions → Add a permission → Microsoft Graph → Delegated permissions. Vyber Mail.Read a offline_access. Klikni na Add permissions. U osobního účtu není potřeba udělovat souhlas správce.',
        'step5_title' => 'Vytvoř client secret',
        'step5_body' => 'Otevři Certificates & secrets → New client secret. Nastav popis „Beatrax“ a platnost 24 měsíců. Hodnotu secretu hned zkopíruj — Azure ji ukáže jen jednou.',
        'step6_title' => 'Vlož své application (client) ID a secret',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Hodnota client secret',
    ],

    'errors' => [
        'pick_provider' => 'Před odesláním vyber poskytovatele.',
        'microsoft_client_id' => 'Zadej application (client) ID — UUID ve tvaru 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Zadej hodnotu client secret, kterou ti Azure ukázal při vytvoření secretu.',
        'google_client_id' => 'Zadej Google OAuth client ID končící na .apps.googleusercontent.com.',
        'google_secret' => 'Zadej Google OAuth client secret začínající na GOCSPX-.',
        'google_published' => 'Potvrď, že máš obrazovku souhlasu OAuth publikovanou v „In production“.',
        'write_failed' => 'OAuth klienta se nepodařilo uložit na disk — zkontroluj oprávnění adresáře se secrety a zkus to znovu.',
    ],
];
