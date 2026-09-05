<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Seadista oma Gmaili OAuth-klient',
    'microsoft_title' => 'Seadista oma Microsoft 365 OAuth-klient',
    'intro' => 'Beatrax kasutab sinu enda Google Cloudi projekti või Azure’i rakenduse registreeringut, nii et sinu volitused ei satu kunagi jagatud serverisse. See on iga teenusepakkuja kohta ühekordne seadistus.',

    'copied' => 'Kopeeritud',
    'cancel' => 'Tühista',
    'save_connect' => 'Salvesta ja ühenda',

    'secret_help' => 'Salvestatakse krüpteeritult selle seadme andmebaasi. Beatrax saadab selle ainult Google\'ile või Microsoftile, et sinu juurdepääsuluba hankida ja uuendada — mitte kuhugi mujale.',

    'gmail' => [
        'step1_title' => 'Ava Google Cloud Console',
        'step1_body' => 'Ava Google Cloud Console uuel vahelehel. Logi sisse Google’i kontoga, mida soovid skannida, ning loo seejärel uus projekt (või vali olemasolev isiklik projekt).',
        'step1_link' => 'Ava Google Cloud Console',
        'step2_title' => 'Luba Gmail API',
        'step2_body' => 'Otsi uues projektis API teegist „Gmail API“ ja klõpsa Enable. See annab projektile õiguse Gmaili sinu nimel kasutada.',
        'step3_title' => 'Seadista OAuthi nõusolekuekraan',
        'step3_body' => 'Ava APIs & Services → OAuth consent screen. Vali kasutajatüübiks „External“, sisesta rakenduse nimeks „Beatrax“ ning oma e-posti aadress toe ja arendaja kontaktiks. Lisa õigus https://www.googleapis.com/auth/gmail.readonly. Klõpsa Save and continue ja seejärel Back to Dashboard.',
        'step4_title' => 'Vii nõusolekuekraan olekusse „In production“',
        'step4_body' => 'Klõpsa OAuthi nõusolekuekraani lehel Publish App ja kinnita. See on kohustuslik — ilma selleta aeguvad Beatraxi saadud värskendusload 7 päeva pärast. Avaldamine ei vaja Google’i ülevaatust, kui ainus kasutaja oled sina.',
        'step4_checkbox' => 'Olen viinud OAuthi nõusolekuekraani olekusse In production',
        'step5_title' => 'Loo OAuthi kliendi ID',
        'step5_body' => 'Ava Credentials → Create Credentials → OAuth Client ID. Vali rakenduse tüübiks „Web application“. Sisesta nimeks „Beatrax“. Kleebi jaotisesse „Authorized redirect URIs“ allolev URI täpselt nii, nagu see on.',
        'step6_title' => 'Kleebi oma kliendi ID ja kliendi saladus',
        'client_id_label' => 'Kliendi ID',
        'client_secret_label' => 'Kliendi saladus',
    ],

    'microsoft' => [
        'step1_title' => 'Ava Azure’i portaal',
        'step1_body' => 'Ava Microsoft Entra halduskeskus uuel vahelehel. Logi sisse Microsofti kontoga, mida soovid skannida.',
        'step1_link' => 'Ava Azure’i portaal',
        'step2_title' => 'Registreeri uus rakendus',
        'step2_body' => 'Ava App registrations → New registration. Pane nimeks „Beatrax“. Vali jaotises „Supported account types“ valik „Accounts in any organizational directory and personal Microsoft accounts“ (see lubab sul sama rakendusega ühendada nii isiklikke Outlook.com kui ka töised Microsoft 365 postkastid).',
        'step3_title' => 'Lisa ümbersuunamise URI',
        'step3_body' => 'Vali samal registreerimisvormil jaotises „Redirect URI“ platvormiks „Web“ ja kleebi allolev URI täpselt nii, nagu see on.',
        'step4_title' => 'Anna õigus Mail.Read',
        'step4_body' => 'Ava API permissions → Add a permission → Microsoft Graph → Delegated permissions. Vali Mail.Read ja offline_access. Klõpsa Add permissions. Isikliku konto puhul ei ole administraatori nõusolekut vaja anda.',
        'step5_title' => 'Loo kliendi saladus',
        'step5_body' => 'Ava Certificates & secrets → New client secret. Sisesta kirjelduseks „Beatrax“ ja aegumiseks 24 kuud. Kopeeri saladuse väärtus kohe — Azure näitab seda ainult üks kord.',
        'step6_title' => 'Kleebi oma rakenduse (kliendi) ID ja saladus',
        'client_id_label' => 'Rakenduse (kliendi) ID',
        'client_secret_label' => 'Kliendi saladuse väärtus',
    ],

    'errors' => [
        'pick_provider' => 'Vali enne esitamist teenusepakkuja.',
        'microsoft_client_id' => 'Sisesta rakenduse (kliendi) ID — UUID kujul 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Sisesta kliendi saladuse väärtus, mille Azure sulle saladuse loomisel näitas.',
        'google_client_id' => 'Sisesta Google’i OAuthi kliendi ID, mis lõpeb .apps.googleusercontent.com.',
        'google_secret' => 'Sisesta Google’i OAuthi kliendi saladus, mis algab GOCSPX-.',
        'google_published' => 'Kinnita, et oled viinud oma OAuthi nõusolekuekraani olekusse „In production“.',
        'write_failed' => 'Sinu OAuthi klienti ei õnnestunud salvestada — kirjutamine selle seadme andmebaasi ebaõnnestus. Proovi uuesti.',
    ],
];
