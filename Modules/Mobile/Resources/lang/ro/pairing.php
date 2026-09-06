<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Dispozitiv împerecheat',
    'page_title' => 'Împerechează un dispozitiv',

    'scan_heading' => 'Împerechează acest dispozitiv',
    'scan_subtitle' => 'Îndreaptă camera spre codul afișat pe celălalt dispozitiv.',
    'camera_permission_pending' => 'Accesul la cameră este dezactivat. Permite-l pentru Beatrax în setările dispozitivului, apoi încearcă din nou.',
    'open_camera' => 'Deschide camera',
    'opening_camera' => 'Se așteaptă accesul la cameră…',
    'close_camera' => 'Închide camera',
    'viewfinder_aria' => 'Vizorul camerei — îndreaptă-l spre codul de pe celălalt dispozitiv',
    'viewfinder_idle' => 'Camera este oprită. Deschide-o pentru a scana codul afișat pe celălalt dispozitiv.',
    'scan_prompt' => 'Scanează codul de pe celălalt dispozitiv',
    'enter_code_instead' => 'Introdu codul în schimb',

    'enter_heading' => 'Introdu codul',
    'camera_off' => 'Accesul la cameră este dezactivat. Introdu în schimb codul de pe celălalt dispozitiv.',
    'camera_off_no_search' => 'Accesul la cameră este oprit, iar căutarea celuilalt dispozitiv în rețea nu funcționează încă pe iPhone — așa că un cod scris nu îl poate găsi singur. Reactivează accesul la cameră pentru Beatrax din setările dispozitivului și scanează codul afișat pe celălalt dispozitiv sau trimite codul aici, iar acest ecran te va întreba unde este.',
    'no_search' => 'Căutarea celuilalt dispozitiv în rețea nu funcționează încă pe iPhone, așa că un cod scris nu îl poate găsi singur. Scanează codul cu camera — aceasta nu are nevoie de nicio căutare în rețea. Dacă nu poți scana, trimite codul și acest ecran te va întreba unde este celălalt dispozitiv.',
    'word_code_aria' => 'Introdu codul din cuvinte de pe celălalt dispozitiv',
    'initiator_address' => 'Unde este celălalt dispozitiv?',
    'initiator_address_help' => 'Adresa lui în această rețea, ca host și port. Computerul o afișează la Dispozitive și sincronizare. Trimite codul din nou după ce ai introdus-o.',
    'submit_code' => 'Trimite codul',
    'cancel' => 'Anulează',
    'skip_import' => 'Continuă fără import',

    'confirm_heading' => 'Compară aceste cuvinte cu celălalt dispozitiv',
    'safety_words_aria' => 'Cuvintele numărului de siguranță: :words',
    'confirm_body' => 'Ambele dispozitive trebuie să afișeze exact aceleași cuvinte. Dacă diferă, apasă pe Anulează — este posibil să fie în curs un atac de tip man-in-the-middle.',
    'awaiting_peer' => 'Se așteaptă confirmarea de la celălalt dispozitiv...',
    'confirm_match' => 'Confirmă — se potrivesc',

    'success_heading' => 'Dispozitiv împerecheat',
    'success_body' => 'Acest dispozitiv este acum de încredere. Datele tale se vor sincroniza imediat ce te conectezi.',
    'encryption_incomplete' => 'Dispozitivul este asociat, dar criptarea datelor stocate pe el nu s-a finalizat. Datele nu sunt încă stocate criptat.',
    'done' => 'Gata',

    'errors' => [
        'relay_unreachable' => 'Celălalt dispozitiv nu poate fi contactat. Asigură-te că ambele sunt în aceeași rețea și că sincronizarea este activată pe desktop.',
        'no_road_home' => 'Acest dispozitiv nu poate căuta în rețea, iar codul scanat nu conține nicio adresă a celuilalt dispozitiv. Cere-i să afișeze un cod nou și scanează-l pe acela.',
        'invalid_code' => 'Acest cod este invalid sau a expirat. Cere celuilalt dispozitiv să genereze unul nou.',
        'already_under_way' => 'Acest dispozitiv a acceptat deja codul și așteaptă confirmarea celuilalt dispozitiv. Dacă nu vine, cere un cod nou și folosește-l pe acela.',
        'vouched_but_refused' => 'Celălalt dispozitiv are încă acel cod, dar acesta nu l-a putut accepta. Cere-i un cod nou și folosește-l pe acela.',
        'code_incomplete' => 'Acest cod nu este complet. Compară-l cu celălalt dispozitiv și introdu-l în întregime.',
        'initiator_address_invalid' => 'Aceasta nu este o adresă pe care acest dispozitiv o poate apela. Introdu-o ca host și port, de exemplu 192.168.1.20:8100.',
        'code_not_accepted' => 'Niciun dispozitiv din această rețea nu a acceptat codul. Verifică codul și dacă celălalt dispozitiv încă îl afișează.',
        'no_peer_answered' => 'Nimic din această rețea nu a răspuns la acel cod. Verifică dacă sincronizarea rulează pe celălalt dispozitiv sau scanează-i codul cu camera — camera nu caută în rețea.',
        'no_peer_answered_ios' => 'Nimic din această rețea nu a răspuns la acel cod. Căutarea celuilalt dispozitiv în rețea încă nu funcționează pe iPhone, așa că scanează-i codul cu camera.',
        'no_peer_answered_camera_off' => 'Nimic din această rețea nu a răspuns la acel cod. Căutarea celuilalt dispozitiv în rețea încă nu funcționează pe iPhone, iar accesul la cameră este dezactivat — reactivează deci accesul la cameră pentru Beatrax în setările dispozitivului și scanează codul de pe celălalt dispozitiv.',
        'rate_limited' => 'Prea multe încercări. Așteaptă un minut și încearcă din nou.',
        'identity_locked' => 'Identitatea dispozitivului tău este blocată. Deblochează aplicația și încearcă din nou.',
        'identity_needs_lock' => 'Configurați mai întâi blocarea aplicației — ea protejează identitatea dispozitivului.',
        'safety_number_changed' => 'Celălalt dispozitiv s-a schimbat în timp ce comparai. Verifică din nou cuvintele de mai jos înainte de a confirma.',
    ],
];
