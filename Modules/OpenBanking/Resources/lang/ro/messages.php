<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Setări',
        'heading' => 'Open banking',
        'subtitle' => 'Preia automat tranzacțiile de la ASN sau SNS prin Enable Banking, un agregator PSD2 terț. Dezactivat implicit.',
        'toggle_label' => 'Activează open banking',
        'toggle_connected' => 'Conectat la :bank prin Enable Banking.',
        'toggle_off_help' => 'Dezactivat implicit. Necesită o confirmare unică și o configurare ghidată.',
        'reconfirm_body' => 'Confirmarea ta a expirat înainte să putem finaliza conectarea. Confirmă din nou pentru a termina activarea open banking.',
        'reconfirm_button' => 'Confirmă din nou pentru a finaliza',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Gestionează open banking',
        'not_connected' => 'Nicio bancă conectată. Conectează una pentru a importa tranzacțiile automat.',
        'expired' => 'Consimțământul a expirat — este necesară reconectarea.',
        'revoked' => 'Banca ta a încheiat conexiunea — reconectează-te.',
        'connected' => 'Conectat la :bank prin Enable Banking. Ultima sincronizare :when.',
        'never' => 'niciodată',
    ],

    'transparency' => [
        'aggregator_label' => 'Agregator',
        'bank_label' => 'Bancă',
        'consent_status_label' => 'Starea consimțământului',
        'pill_expired' => 'Expirat — reconectează-te',
        'pill_expiring' => 'Expiră în curând',
        'pill_connected' => 'Conectat',
        'pill_revoked' => 'Încheiată de banca ta — reconectează-te',
        'whats_fetched_label' => 'Ce se preia',
        'whats_fetched' => 'Tranzacții înregistrate și solduri, ultimele 90 de zile',
        'last_successful_sync_label' => 'Ultima sincronizare reușită',
        'never' => 'Niciodată',
        'last_attempt_label' => 'Ultima încercare',
        'last_attempt_failed' => ':when — eșuată (:reason)',
        'reason_consent_expired' => 'consimțământ expirat',
        'reason_error' => 'eroare',
        'reason_truncated' => 'oprită devreme',
        'reason_nothing_imported' => 'nu s-a putut înregistra nimic',
        'reason_consent_revoked' => 'încheiată de banca ta',
        'disconnect_button' => 'Deconectează',
    ],

    'consent_banner' => [
        'heading' => 'Consimțământul a expirat — reconectează-te',
        'heading_revoked' => 'Banca ta a încheiat conexiunea',
        'body' => 'Ultima sincronizare reușită a fost :when. Reconectează-te pentru a relua sincronizarea automată.',
        'body_revoked' => 'Banca ta sau Enable Banking a retras accesul, așa că sincronizarea s-a oprit. Ultima sincronizare reușită a fost :when. Reconectează-te ca să reia.',
        'never' => 'niciodată',
        'reconnect' => 'Reconectează',
    ],

    'sync' => [
        'review_import' => 'Verifică importul',
        'reconnect_first' => 'Reconectează-te întâi',
        'auto_caption' => 'Se sincronizează automat o dată pe zi.',
        'sync_now' => 'Sincronizează acum',

        'consent_expired' => 'Consimțământul a expirat — reconectează-te.',
        'unavailable' => 'Enable Banking este temporar indisponibil. Încearcă din nou în scurt timp.',
        'new_found' => ':count tranzacție nouă găsită.|:count tranzacții noi găsite.|:count de tranzacții noi găsite.',
        'none' => 'Nicio tranzacție nouă.',
        'none_importable' => 'Banca ta a trimis tranzacții, dar niciuna nu a putut fi înregistrată. Deschide verificarea importului ca să vezi de ce.',
        'in_progress' => 'O sincronizare este deja în curs. Încercați din nou peste o clipă.',
        'truncated' => 'Banca ta avea mai multe tranzacții decât poate prelua o sincronizare, așa că această rulare s-a oprit devreme. Nimic nu a fost înregistrat ca sincronizat — următoarea sincronizare pornește din același punct.',
    ],

    'disconnect' => [
        'heading' => 'Deconectezi open banking?',
        'body' => 'Astfel se șterg datele tale de autentificare Enable Banking și consimțământul stocat. Sincronizarea automată se oprește imediat. Tranzacțiile deja importate în Beatrax nu sunt afectate.',
        'confirm' => 'Deconectează',
        'cancel' => 'Rămâi conectat',
    ],

    'ics' => [
        'section_label' => 'Import din fișier — nu se stochează date de autentificare',
        'heading' => 'Extras de cont card de credit ICS',
        'step_login' => 'Autentifică-te',
        'step_download' => 'Descarcă extrasul',
        'pdf_statement' => 'Extras PDF',
        'step_drop' => 'Trage-l mai jos',
        'drop_zone_label' => 'Trage aici fișierul cu extrasul',
        'drop_zone_hint' => 'sau caută un fișier',
        'browse_aria' => 'Caută un fișier cu extras ICS',
        'import_button' => 'Importă extrasul',
        'validation' => [
            'required' => 'Trage aici extrasul ICS descărcat din Mijn ICS.',
            'max' => 'Fișierul este prea mare. Extrasele PDF de la ICS au de obicei sub 1 MB fiecare.',
            'extensions' => 'Acesta nu este un PDF. Mijn ICS exportă doar extrase PDF.',
        ],
        'could_not_read' => 'Nu s-a putut citi :filename. Eroarea completă se află în /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Înainte să conectezi un terț',
        'body' => 'Activarea open banking trimite consimțământul de autentificare la bancă, iar apoi datele tale despre tranzacții și solduri, direct de pe acest dispozitiv către Enable Banking și către banca ta. Beatrax nu operează un server care să vadă aceste date — dar Enable Banking și banca ta le văd. Este diferit de orice altă metodă de import din Beatrax, care nu trimite niciodată date nicăieri.',
        'acknowledge' => 'Înțeleg că datele mele despre tranzacții vor fi partajate cu Enable Banking și cu banca mea.',
        'confirm' => 'Activează open banking',
        'cancel' => 'Anulează',
    ],

    'wizard' => [
        'heading' => 'Conectează-ți banca',
        'intro' => 'Beatrax folosește propria ta aplicație Enable Banking, așa că datele tale de autentificare nu ajung niciodată pe un server comun. Este o configurare unică pentru fiecare bancă.',

        'step1_title' => 'Generează perechea locală de chei',
        'step1_body' => 'Beatrax generează o pereche de chei RSA pe acest dispozitiv. Cheia privată nu îl părăsește niciodată.',
        'generate_keypair' => 'Generează perechea de chei',
        'public_key_label' => 'Cheie publică',
        'copy_public_key' => 'Copiază cheia publică',
        'copied' => 'Copiat',
        'redirect_uri_label' => 'URI de redirecționare',
        'copy_redirect_uri' => 'Copiază URI-ul de redirecționare',

        'step2_title' => 'Înregistrează aplicația în Enable Banking',
        'step2_body' => 'Deschide portalul pentru dezvoltatori Enable Banking, creează o aplicație și lipește cheia publică și URI-ul de redirecționare de la pasul 1.',
        'open_portal' => 'Deschide portalul Enable Banking ↗',

        'step3_title' => 'Lipește ID-ul aplicației',
        'application_id_label' => 'ID aplicație',
        'step3_help' => 'Acesta este stocat într-un fișier local, în afara bazei de date, cu permisiuni restrictive, și nu părăsește niciodată acest dispozitiv.',

        'step4_title' => 'Alege-ți banca',
        'via_enable_banking' => 'prin Enable Banking',
        'other_institution' => 'Altă instituție',
        'institution_id_placeholder' => 'ID instituție',

        'step5_title' => 'Finalizează consimțământul în browser',
        'step5_body' => 'Dă clic mai jos pentru a deschide ecranul de autentificare și consimțământ al băncii tale. Finalizează autentificarea și eventualul pas de verificare în doi pași, apoi vei fi adus automat înapoi aici pentru a termina activarea open banking.',
        // i18n-review: ro · step5_body_touch — the same line for a touch
        // screen; check the verb governs this case.
        'step5_body_touch' => 'Atinge mai jos pentru a deschide ecranul de autentificare și consimțământ al băncii tale. Finalizează autentificarea și eventualul pas de verificare în doi pași, apoi vei fi adus automat înapoi aici pentru a termina activarea open banking.',

        'cancel' => 'Anulează',
        'continue' => 'Continuă →',
        'continue_to_bank' => 'Continuă către :bank →',
        'your_bank' => 'banca ta',

        'errors' => [
            'save_keypair_failed' => 'Perechea de chei nu a putut fi salvată pe disc — verifică permisiunile directorului de secrete și încearcă din nou.',
            'generate_failed' => 'Nu s-a putut genera o pereche de chei pe acest dispozitiv — verifică configurația OpenSSL.',
            'export_failed' => 'Perechea de chei generată nu a putut fi exportată.',
            'read_public_failed' => 'Cheia publică generată nu a putut fi citită.',
            'generate_first' => 'Generează o pereche de chei înainte de a continua.',
            'paste_application_id' => 'Lipește ID-ul aplicației din portalul Enable Banking înainte de a continua.',
            'save_application_id_failed' => 'ID-ul aplicației nu a putut fi salvat pe disc — verifică permisiunile directorului de secrete și încearcă din nou.',
            'choose_bank' => 'Alege o bancă înainte de a continua.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Finalizează întâi asistentul de configurare open banking.',
        'no_bank_chosen' => 'Alege o bancă înainte de conectare.',
        'no_consent_url' => 'Enable Banking nu a returnat un URL de consimțământ.',
        'unparseable_consent_url' => 'Enable Banking a returnat un URL de consimțământ care nu poate fi interpretat.',
        'non_public_consent_host' => 'Enable Banking a returnat o gazdă de consimțământ nepublică.',
        'unsafe_consent_url' => 'Enable Banking a returnat un URL de consimțământ nesigur.',
        'no_authorization_code' => 'Apelul de întoarcere Enable Banking nu a returnat un cod de autorizare.',
        'no_session_id' => 'Enable Banking nu a returnat un ID de sesiune.',
        'oauth_state_mismatch' => 'Acest link de conectare a expirat sau a fost deja folosit. Începe din nou conectarea băncii.',
    ],
];
