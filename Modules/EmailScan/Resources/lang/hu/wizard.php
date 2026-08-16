<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Állítsd be a saját Gmail OAuth-kliensedet',
    'microsoft_title' => 'Állítsd be a saját Microsoft 365 OAuth-kliensedet',
    'intro' => 'A Beatrax a saját Google Cloud-projektedet / Azure-alkalmazásregisztrációdat használja, így a hitelesítő adataid soha nem kerülnek közös kiszolgálóra. Ez szolgáltatónként egyszeri beállítás.',

    'copied' => 'Másolva',
    'cancel' => 'Mégse',
    'save_connect' => 'Mentés és csatlakoztatás',

    'secret_help' => 'Ezeket az adatbázison kívüli helyi konfigurációs fájlban, szigorú jogosultságokkal tároljuk, és soha nem hagyják el ezt az eszközt.',

    'gmail' => [
        'step1_title' => 'Nyisd meg a Google Cloud Console-t',
        'step1_body' => 'Nyisd meg a Google Cloud Console-t egy új lapon. Jelentkezz be azzal a Google-fiókkal, amelyet vizsgálni szeretnél, majd hozz létre egy új projektet (vagy válassz egy meglévő személyes projektet).',
        'step1_link' => 'Google Cloud Console megnyitása',
        'step2_title' => 'Engedélyezd a Gmail API-t',
        'step2_body' => 'Az új projektben keresd meg a „Gmail API” elemet az API-könyvtárban, és kattints az Enable gombra. Ezzel a projekt a nevedben hívhatja a Gmailt.',
        'step3_title' => 'Állítsd be az OAuth-hozzájárulási képernyőt',
        'step3_body' => 'Nyisd meg az APIs & Services → OAuth consent screen oldalt. Válaszd a „External” felhasználótípust, add meg alkalmazásnévként a „Beatrax” nevet, támogatási és fejlesztői kapcsolattartóként pedig a saját e-mail-címed. Add hozzá a https://www.googleapis.com/auth/gmail.readonly hatókört. Kattints a Save and continue, majd a Back to Dashboard gombra.',
        'step4_title' => 'Állítsd a hozzájárulási képernyőt „In production” állapotba',
        'step4_body' => 'Az OAuth consent screen oldalon kattints a Publish App gombra, és erősítsd meg. Erre szükség van — enélkül a Beatrax által kapott frissítési tokenek 7 nap után lejárnak. A közzététel nem igényel Google-ellenőrzést, ha az egyetlen felhasználó te vagy.',
        'step4_checkbox' => 'Az OAuth-hozzájárulási képernyőt In production állapotba állítottam',
        'step5_title' => 'Hozd létre az OAuth-kliensazonosítót',
        'step5_body' => 'Nyisd meg a Credentials → Create Credentials → OAuth Client ID menüpontot. Válaszd a „Web application” alkalmazástípust. Add meg a „Beatrax” nevet. Az „Authorized redirect URIs” alatt illeszd be pontosan az alábbi URI-t.',
        'step6_title' => 'Illeszd be a kliensazonosítót és a titkos kulcsot',
        'client_id_label' => 'Kliensazonosító',
        'client_secret_label' => 'Titkos klienskulcs',
    ],

    'microsoft' => [
        'step1_title' => 'Nyisd meg az Azure Portalt',
        'step1_body' => 'Nyisd meg a Microsoft Entra felügyeleti központot egy új lapon. Jelentkezz be azzal a Microsoft-fiókkal, amelyet vizsgálni szeretnél.',
        'step1_link' => 'Azure Portal megnyitása',
        'step2_title' => 'Regisztrálj egy új alkalmazást',
        'step2_body' => 'Nyisd meg az App registrations → New registration menüpontot. Nevezd el „Beatrax” néven. A „Supported account types” alatt válaszd az „Accounts in any organizational directory and personal Microsoft accounts” lehetőséget (így ugyanazzal az alkalmazással csatlakoztathatsz személyes Outlook.com- és céges Microsoft 365-postafiókokat).',
        'step3_title' => 'Add meg az átirányítási URI-t',
        'step3_body' => 'Ugyanezen a regisztrációs űrlapon a „Redirect URI” alatt válaszd a „Web” platformot, és illeszd be pontosan az alábbi URI-t.',
        'step4_title' => 'Adj Mail.Read jogosultságot',
        'step4_body' => 'Nyisd meg az API permissions → Add a permission → Microsoft Graph → Delegated permissions menüpontot. Válaszd ki a Mail.Read és az offline_access elemet. Kattints az Add permissions gombra. Személyes fiókhoz nincs szükség rendszergazdai jóváhagyásra.',
        'step5_title' => 'Hozz létre egy titkos klienskulcsot',
        'step5_body' => 'Nyisd meg a Certificates & secrets → New client secret menüpontot. Adj meg „Beatrax” leírást és 24 hónapos lejáratot. Azonnal másold ki a titkos értéket — az Azure csak egyszer mutatja meg.',
        'step6_title' => 'Illeszd be az alkalmazás- (kliens-) azonosítót és a titkos kulcsot',
        'client_id_label' => 'Alkalmazás- (kliens-) azonosító',
        'client_secret_label' => 'A titkos klienskulcs értéke',
    ],

    'errors' => [
        'pick_provider' => 'Válassz szolgáltatót a beküldés előtt.',
        'microsoft_client_id' => 'Add meg az alkalmazás- (kliens-) azonosítót — egy UUID-t, például 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Add meg azt a titkos kliensértéket, amelyet az Azure a titok létrehozásakor mutatott.',
        'google_client_id' => 'Adj meg egy .apps.googleusercontent.com végződésű Google OAuth-kliensazonosítót.',
        'google_secret' => 'Adj meg egy GOCSPX- kezdetű Google OAuth-titkoskulcsot.',
        'google_published' => 'Erősítsd meg, hogy az OAuth-hozzájárulási képernyőt „In production” állapotba állítottad.',
        'write_failed' => 'Az OAuth-klienst nem sikerült lemezre menteni — ellenőrizd a titkokat tároló könyvtár jogosultságait, és próbáld újra.',
    ],
];
