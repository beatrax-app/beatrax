<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Sukurk savo Gmail OAuth klientą',
    'microsoft_title' => 'Sukurk savo Microsoft 365 OAuth klientą',
    'intro' => 'Beatrax naudoja tavo paties Google Cloud projektą arba Azure programos registraciją, todėl tavo prisijungimo duomenys niekada nepatenka į bendrą serverį. Tai vienkartinė sąranka kiekvienam tiekėjui.',

    'copied' => 'Nukopijuota',
    'cancel' => 'Atšaukti',
    'save_connect' => 'Išsaugoti ir prijungti',

    'secret_help' => 'Jie saugomi vietiniame konfigūracijos faile už duomenų bazės ribų su griežtomis teisėmis ir niekada neišeina iš šio įrenginio.',

    'gmail' => [
        'step1_title' => 'Atverk Google Cloud Console',
        'step1_body' => 'Atverk Google Cloud Console naujoje kortelėje. Prisijunk ta Google paskyra, kurią nori nuskaityti, tada sukurk naują projektą (arba pasirink esamą asmeninį projektą).',
        'step1_link' => 'Atverti Google Cloud Console',
        'step2_title' => 'Įjunk Gmail API',
        'step2_body' => 'Naujame projekte API bibliotekoje surask „Gmail API“ ir spustelėk Enable. Taip projektas gaus teisę tavo vardu kreiptis į Gmail.',
        'step3_title' => 'Sukonfigūruok OAuth sutikimo langą',
        'step3_body' => 'Atverk APIs & Services → OAuth consent screen. Pasirink naudotojo tipą „External“, kaip programos pavadinimą įvesk „Beatrax“, o kaip palaikymo ir kūrėjo kontaktą — savo el. pašto adresą. Pridėk sritį https://www.googleapis.com/auth/gmail.readonly. Spustelėk Save and continue, tada Back to Dashboard.',
        'step4_title' => 'Perkelk sutikimo langą į būseną „In production“',
        'step4_body' => 'OAuth consent screen puslapyje spustelėk Publish App ir patvirtink. Tai būtina — kitaip Beatrax gaunami atnaujinimo raktai nustoja galioti po 7 dienų. Kai vienintelis naudotojas esi tu, publikavimui Google patikros nereikia.',
        'step4_checkbox' => 'Perkėliau OAuth sutikimo langą į būseną In production',
        'step5_title' => 'Sukurk OAuth kliento ID',
        'step5_body' => 'Atverk Credentials → Create Credentials → OAuth Client ID. Pasirink programos tipą „Web application“. Pavadink ją „Beatrax“. Skiltyje „Authorized redirect URIs“ tiksliai įklijuok žemiau esantį URI.',
        'step6_title' => 'Įklijuok savo kliento ID ir kliento paslaptį',
        'client_id_label' => 'Kliento ID',
        'client_secret_label' => 'Kliento paslaptis',
    ],

    'microsoft' => [
        'step1_title' => 'Atverk Azure portalą',
        'step1_body' => 'Atverk Microsoft Entra administravimo centrą naujoje kortelėje. Prisijunk ta Microsoft paskyra, kurią nori nuskaityti.',
        'step1_link' => 'Atverti Azure portalą',
        'step2_title' => 'Užregistruok naują programą',
        'step2_body' => 'Atverk App registrations → New registration. Pavadink ją „Beatrax“. Skiltyje „Supported account types“ pasirink „Accounts in any organizational directory and personal Microsoft accounts“ (taip ta pačia programa galėsi prijungti tiek asmenines Outlook.com, tiek darbines Microsoft 365 pašto dėžutes).',
        'step3_title' => 'Pridėk nukreipimo URI',
        'step3_body' => 'Toje pačioje registracijos formoje skiltyje „Redirect URI“ pasirink platformą „Web“ ir tiksliai įklijuok žemiau esantį URI.',
        'step4_title' => 'Suteik Mail.Read leidimą',
        'step4_body' => 'Atverk API permissions → Add a permission → Microsoft Graph → Delegated permissions. Pasirink Mail.Read ir offline_access. Spustelėk Add permissions. Asmeninei paskyrai administratoriaus sutikimo suteikti nereikia.',
        'step5_title' => 'Sukurk kliento paslaptį',
        'step5_body' => 'Atverk Certificates & secrets → New client secret. Aprašui įrašyk „Beatrax“, o galiojimą nustatyk 24 mėnesiams. Iš karto nusikopijuok paslapties reikšmę — Azure ją parodo tik vieną kartą.',
        'step6_title' => 'Įklijuok savo programos (kliento) ID ir paslaptį',
        'client_id_label' => 'Programos (kliento) ID',
        'client_secret_label' => 'Kliento paslapties reikšmė',
    ],

    'errors' => [
        'pick_provider' => 'Prieš pateikdamas pasirink tiekėją.',
        'microsoft_client_id' => 'Įvesk programos (kliento) ID — UUID, pavyzdžiui 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Įvesk kliento paslapties reikšmę, kurią Azure parodė kuriant paslaptį.',
        'google_client_id' => 'Įvesk Google OAuth kliento ID, kuris baigiasi .apps.googleusercontent.com.',
        'google_secret' => 'Įvesk Google OAuth kliento paslaptį, kuri prasideda GOCSPX-.',
        'google_published' => 'Patvirtink, kad perkėlei OAuth sutikimo langą į būseną „In production“.',
        'write_failed' => 'Nepavyko įrašyti tavo OAuth kliento į diską — patikrink paslapčių katalogo teises ir bandyk dar kartą.',
    ],
];
