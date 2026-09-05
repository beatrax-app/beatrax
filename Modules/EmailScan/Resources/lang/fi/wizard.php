<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Määritä oma Gmail OAuth -asiakas',
    'microsoft_title' => 'Määritä oma Microsoft 365 OAuth -asiakas',
    'intro' => 'Beatrax käyttää omaa Google Cloud -projektiasi tai Azure-sovellusrekisteröintiäsi, joten tunnuksesi eivät koskaan päädy jaetulle palvelimelle. Tämä tehdään kerran palveluntarjoajaa kohti.',

    'copied' => 'Kopioitu',
    'cancel' => 'Peruuta',
    'save_connect' => 'Tallenna ja yhdistä',

    'secret_help' => 'Tallennetaan salattuna tämän laitteen tietokantaan. Beatrax lähettää sen vain Googlelle tai Microsoftille hakeakseen ja uusiakseen käyttöoikeustunnisteesi — ei minnekään muualle.',

    'gmail' => [
        'step1_title' => 'Avaa Google Cloud Console',
        'step1_body' => 'Avaa Google Cloud Console uudessa välilehdessä. Kirjaudu sisään sillä Google-tilillä, jonka haluat skannata, ja luo sitten uusi projekti (tai valitse olemassa oleva henkilökohtainen projekti).',
        'step1_link' => 'Avaa Google Cloud Console',
        'step2_title' => 'Ota Gmail API käyttöön',
        'step2_body' => 'Hae uudessa projektissa API Library -kirjastosta ”Gmail API” ja napsauta Enable. Tämä antaa projektille oikeuden kutsua Gmailia puolestasi.',
        'step3_title' => 'Määritä OAuth consent screen',
        'step3_body' => 'Avaa APIs & Services → OAuth consent screen. Valitse User type ”External”, anna sovelluksen nimeksi ”Beatrax” ja oma sähköpostiosoitteesi tuki- ja kehittäjäyhteystiedoksi. Lisää scope https://www.googleapis.com/auth/gmail.readonly. Napsauta Save and continue ja sitten Back to Dashboard.',
        'step4_title' => 'Julkaise consent screen tilaan ”In production”',
        'step4_body' => 'Napsauta OAuth consent screen -sivulla Publish App ja vahvista. Tämä on pakollista — ilman sitä Beatraxin saamat refresh-tokenit vanhenevat 7 päivässä. Julkaisu ei vaadi Googlen tarkistusta, kun ainoa käyttäjä olet sinä.',
        'step4_checkbox' => 'Olen julkaissut OAuth consent screenin tilaan In production',
        'step5_title' => 'Luo OAuth Client ID',
        'step5_body' => 'Avaa Credentials → Create Credentials → OAuth Client ID. Valitse sovellustyypiksi ”Web application”. Aseta nimeksi ”Beatrax”. Liitä alla oleva URI täsmälleen kohtaan ”Authorized redirect URIs”.',
        'step6_title' => 'Liitä client ID ja client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Avaa Azure Portal',
        'step1_body' => 'Avaa Microsoft Entra -hallintakeskus uudessa välilehdessä. Kirjaudu sisään sillä Microsoft-tilillä, jonka haluat skannata.',
        'step1_link' => 'Avaa Azure Portal',
        'step2_title' => 'Rekisteröi uusi sovellus',
        'step2_body' => 'Avaa App registrations → New registration. Anna nimeksi ”Beatrax”. Valitse kohdasta ”Supported account types” vaihtoehto ”Accounts in any organizational directory and personal Microsoft accounts” (näin voit yhdistää samalla sovelluksella sekä henkilökohtaiset Outlook.com-postilaatikot että työkäytön Microsoft 365 -postilaatikot).',
        'step3_title' => 'Lisää redirect URI',
        'step3_body' => 'Valitse samassa rekisteröintilomakkeessa kohdassa ”Redirect URI” alustaksi ”Web” ja liitä alla oleva URI täsmälleen.',
        'step4_title' => 'Myönnä Mail.Read-oikeus',
        'step4_body' => 'Avaa API permissions → Add a permission → Microsoft Graph → Delegated permissions. Valitse Mail.Read ja offline_access. Napsauta Add permissions. Henkilökohtaisella tilillä ei tarvitse myöntää järjestelmänvalvojan suostumusta.',
        'step5_title' => 'Luo client secret',
        'step5_body' => 'Avaa Certificates & secrets → New client secret. Aseta kuvaukseksi ”Beatrax” ja voimassaoloajaksi 24 kuukautta. Kopioi salaisuuden arvo heti — Azure näyttää sen vain kerran.',
        'step6_title' => 'Liitä application (client) ID ja secret',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret -arvo',
    ],

    'errors' => [
        'pick_provider' => 'Valitse palveluntarjoaja ennen lähettämistä.',
        'microsoft_client_id' => 'Anna application (client) ID — UUID, kuten 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Anna client secret -arvo, jonka Azure näytti salaisuutta luodessasi.',
        'google_client_id' => 'Anna Google OAuth client ID, joka päättyy .apps.googleusercontent.com.',
        'google_secret' => 'Anna Google OAuth client secret, joka alkaa GOCSPX-.',
        'google_published' => 'Vahvista, että olet julkaissut OAuth consent screenin tilaan ”In production”.',
        'write_failed' => 'OAuth-asiakastasi ei voitu tallentaa — kirjoitus tämän laitteen tietokantaan epäonnistui. Yritä uudelleen.',
    ],
];
