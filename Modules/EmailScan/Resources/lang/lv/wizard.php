<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Iestatiet savu Gmail OAuth klientu',
    'microsoft_title' => 'Iestatiet savu Microsoft 365 OAuth klientu',
    'intro' => 'Beatrax izmanto jūsu paša Google Cloud projektu vai Azure lietotnes reģistrāciju, tāpēc jūsu akreditācijas dati nekad nenonāk kopīgotā serverī. Šī ir vienreizēja iestatīšana katram pakalpojuma sniedzējam.',

    'copied' => 'Nokopēts',
    'cancel' => 'Atcelt',
    'save_connect' => 'Saglabāt un pievienot',

    'secret_help' => 'Tie tiek glabāti lokālā konfigurācijas failā ārpus datubāzes ar ierobežojošām atļaujām un nekad nepamet šo ierīci.',

    'gmail' => [
        'step1_title' => 'Atveriet Google Cloud Console',
        'step1_body' => 'Atveriet Google Cloud Console jaunā cilnē. Piesakieties ar to Google kontu, kuru vēlaties skenēt, pēc tam izveidojiet jaunu projektu (vai izvēlieties esošu personīgo projektu).',
        'step1_link' => 'Atvērt Google Cloud Console',
        'step2_title' => 'Ieslēdziet Gmail API',
        'step2_body' => 'Jaunajā projektā API bibliotēkā sameklējiet „Gmail API” un noklikšķiniet Enable. Tas ļauj projektam jūsu vārdā izsaukt Gmail.',
        'step3_title' => 'Konfigurējiet OAuth piekrišanas ekrānu',
        'step3_body' => 'Atveriet APIs & Services → OAuth consent screen. Kā User type izvēlieties „External”, kā lietotnes nosaukumu ievadiet „Beatrax”, bet kā atbalsta un izstrādātāja kontaktu — savu e-pasta adresi. Pievienojiet tvērumu https://www.googleapis.com/auth/gmail.readonly. Noklikšķiniet Save and continue un pēc tam Back to Dashboard.',
        'step4_title' => 'Pārslēdziet piekrišanas ekrānu uz „In production”',
        'step4_body' => 'OAuth consent screen lapā noklikšķiniet Publish App un apstipriniet. Tas ir obligāti — bez tā atsvaidzināšanas marķieri, ko saņem Beatrax, beidzas pēc 7 dienām. Publicēšanai nav nepieciešama Google pārbaude, ja vienīgais lietotājs esat jūs.',
        'step4_checkbox' => 'OAuth piekrišanas ekrāns ir publicēts statusā In production',
        'step5_title' => 'Izveidojiet OAuth Client ID',
        'step5_body' => 'Atveriet Credentials → Create Credentials → OAuth Client ID. Kā lietojumprogrammas veidu izvēlieties „Web application”. Kā nosaukumu norādiet „Beatrax”. Sadaļā „Authorized redirect URIs” precīzi ielīmējiet zemāk redzamo URI.',
        'step6_title' => 'Ielīmējiet savu client ID un client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Atveriet Azure portālu',
        'step1_body' => 'Atveriet Microsoft Entra administrācijas centru jaunā cilnē. Piesakieties ar to Microsoft kontu, kuru vēlaties skenēt.',
        'step1_link' => 'Atvērt Azure portālu',
        'step2_title' => 'Reģistrējiet jaunu lietotni',
        'step2_body' => 'Atveriet App registrations → New registration. Nosauciet to „Beatrax”. Sadaļā „Supported account types” izvēlieties „Accounts in any organizational directory and personal Microsoft accounts” (tas ļauj ar vienu lietotni pievienot gan personīgās Outlook.com, gan darba Microsoft 365 pastkastes).',
        'step3_title' => 'Pievienojiet novirzīšanas URI',
        'step3_body' => 'Tajā pašā reģistrācijas formā sadaļā „Redirect URI” izvēlieties platformu „Web” un precīzi ielīmējiet zemāk redzamo URI.',
        'step4_title' => 'Piešķiriet Mail.Read atļauju',
        'step4_body' => 'Atveriet API permissions → Add a permission → Microsoft Graph → Delegated permissions. Atlasiet Mail.Read un offline_access. Noklikšķiniet Add permissions. Personīgam kontam administratora piekrišana nav nepieciešama.',
        'step5_title' => 'Izveidojiet client secret',
        'step5_body' => 'Atveriet Certificates & secrets → New client secret. Kā aprakstu norādiet „Beatrax” un derīguma termiņu 24 mēneši. Nekavējoties nokopējiet secret vērtību — Azure to parāda tikai vienu reizi.',
        'step6_title' => 'Ielīmējiet savu application (client) ID un secret',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret vērtība',
    ],

    'errors' => [
        'pick_provider' => 'Pirms iesniegšanas izvēlieties pakalpojuma sniedzēju.',
        'microsoft_client_id' => 'Ievadiet application (client) ID — UUID, piemēram, 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Ievadiet client secret vērtību, ko Azure parādīja, kad izveidojāt secret.',
        'google_client_id' => 'Ievadiet Google OAuth client ID, kas beidzas ar .apps.googleusercontent.com.',
        'google_secret' => 'Ievadiet Google OAuth client secret, kas sākas ar GOCSPX-.',
        'google_published' => 'Apstipriniet, ka OAuth piekrišanas ekrāns ir pārslēgts uz „In production”.',
        'write_failed' => 'Neizdevās saglabāt jūsu OAuth klientu diskā — pārbaudiet noslēpumu direktorijas atļaujas un mēģiniet vēlreiz.',
    ],
];
