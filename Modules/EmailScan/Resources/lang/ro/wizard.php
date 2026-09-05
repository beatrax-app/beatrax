<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Configurează-ți clientul OAuth pentru Gmail',
    'microsoft_title' => 'Configurează-ți clientul OAuth pentru Microsoft 365',
    'intro' => 'Beatrax folosește propriul tău proiect Google Cloud / propria înregistrare de aplicație Azure, așa că datele tale de autentificare nu ajung niciodată pe un server comun. Este o configurare unică pentru fiecare furnizor.',

    'copied' => 'Copiat',
    'cancel' => 'Anulează',
    'save_connect' => 'Salvează și conectează',

    'secret_help' => 'Se stochează criptat în baza de date de pe acest dispozitiv. Beatrax îl trimite doar către Google sau Microsoft, ca să obțină și să reînnoiască tokenul tău de acces — nicăieri altundeva.',

    'gmail' => [
        'step1_title' => 'Deschide Google Cloud Console',
        'step1_body' => 'Deschide Google Cloud Console într-o filă nouă. Autentifică-te cu contul Google pe care vrei să îl scanezi, apoi creează un proiect nou (sau selectează un proiect personal existent).',
        'step1_link' => 'Deschide Google Cloud Console',
        'step2_title' => 'Activează Gmail API',
        'step2_body' => 'În proiectul nou, caută „Gmail API” în API Library și dă clic pe Enable. Astfel proiectul poate apela Gmail în numele tău.',
        'step3_title' => 'Configurează ecranul de consimțământ OAuth',
        'step3_body' => 'Deschide APIs & Services → OAuth consent screen. Alege tipul de utilizator „External”, introdu „Beatrax” ca nume al aplicației și propria adresă de e-mail ca persoană de contact pentru asistență și pentru dezvoltator. Adaugă domeniul https://www.googleapis.com/auth/gmail.readonly. Dă clic pe Save and continue, apoi pe Back to Dashboard.',
        'step4_title' => 'Trece ecranul de consimțământ în „In production”',
        'step4_body' => 'În pagina OAuth consent screen, dă clic pe Publish App și confirmă. Este obligatoriu — fără asta, tokenurile de reîmprospătare primite de Beatrax expiră după 7 zile. Publicarea nu necesită o verificare Google atâta timp cât singurul utilizator ești tu.',
        'step4_checkbox' => 'Am publicat ecranul de consimțământ OAuth în In production',
        'step5_title' => 'Creează OAuth Client ID',
        'step5_body' => 'Deschide Credentials → Create Credentials → OAuth Client ID. Alege tipul de aplicație „Web application”. Setează numele „Beatrax”. La „Authorized redirect URIs” lipește exact URI-ul de mai jos.',
        'step6_title' => 'Lipește ID-ul clientului și secretul clientului',
        'client_id_label' => 'ID client',
        'client_secret_label' => 'Secret client',
    ],

    'microsoft' => [
        'step1_title' => 'Deschide Azure Portal',
        'step1_body' => 'Deschide centrul de administrare Microsoft Entra într-o filă nouă. Autentifică-te cu contul Microsoft pe care vrei să îl scanezi.',
        'step1_link' => 'Deschide Azure Portal',
        'step2_title' => 'Înregistrează o aplicație nouă',
        'step2_body' => 'Deschide App registrations → New registration. Denumește-o „Beatrax”. La „Supported account types” alege „Accounts in any organizational directory and personal Microsoft accounts” (astfel poți conecta cu aceeași aplicație atât căsuțe personale Outlook.com, cât și căsuțe de serviciu Microsoft 365).',
        'step3_title' => 'Adaugă URI-ul de redirecționare',
        'step3_body' => 'În același formular de înregistrare, la „Redirect URI”, alege platforma „Web” și lipește exact URI-ul de mai jos.',
        'step4_title' => 'Acordă permisiunea Mail.Read',
        'step4_body' => 'Deschide API permissions → Add a permission → Microsoft Graph → Delegated permissions. Selectează Mail.Read și offline_access. Dă clic pe Add permissions. Pentru un cont personal nu este nevoie de consimțământ de administrator.',
        'step5_title' => 'Creează un secret de client',
        'step5_body' => 'Deschide Certificates & secrets → New client secret. Setează descrierea „Beatrax” și o expirare de 24 de luni. Copiază imediat valoarea secretului — Azure o afișează o singură dată.',
        'step6_title' => 'Lipește ID-ul aplicației (clientului) și secretul',
        'client_id_label' => 'ID aplicație (client)',
        'client_secret_label' => 'Valoarea secretului de client',
    ],

    'errors' => [
        'pick_provider' => 'Alege un furnizor înainte de trimitere.',
        'microsoft_client_id' => 'Introdu ID-ul aplicației (clientului) — un UUID de forma 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Introdu valoarea secretului de client pe care Azure ți-a arătat-o când ai creat secretul.',
        'google_client_id' => 'Introdu un ID de client OAuth Google care se termină în .apps.googleusercontent.com.',
        'google_secret' => 'Introdu un secret de client OAuth Google care începe cu GOCSPX-.',
        'google_published' => 'Confirmă că ai trecut ecranul de consimțământ OAuth în „In production”.',
        'write_failed' => 'Clientul OAuth nu a putut fi salvat — scrierea în baza de date de pe acest dispozitiv a eșuat. Încearcă din nou.',
    ],
];
