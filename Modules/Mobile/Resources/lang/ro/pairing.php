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
    'word_code_aria' => 'Introdu codul din cuvinte de pe celălalt dispozitiv',
    'submit_code' => 'Trimite codul',
    'cancel' => 'Anulează',

    'confirm_heading' => 'Compară aceste cuvinte cu celălalt dispozitiv',
    'safety_words_aria' => 'Cuvintele numărului de siguranță: :words',
    'confirm_body' => 'Ambele dispozitive trebuie să afișeze exact aceleași cuvinte. Dacă diferă, apasă pe Anulează — este posibil să fie în curs un atac de tip man-in-the-middle.',
    'awaiting_peer' => 'Se așteaptă confirmarea de la celălalt dispozitiv...',
    'confirm_match' => 'Confirmă — se potrivesc',

    'success_heading' => 'Dispozitiv împerecheat',
    'success_body' => 'Acest dispozitiv este acum de încredere. Datele tale se vor sincroniza imediat ce te conectezi.',
    'done' => 'Gata',

    'errors' => [
        'relay_unreachable' => 'Celălalt dispozitiv nu poate fi contactat. Asigură-te că ambele sunt în aceeași rețea și că sincronizarea este activată pe desktop.',
        'import_needs_qr' => 'Scanează codul QR afișat pe celălalt dispozitiv pentru a importa.',
        'invalid_code' => 'Acest cod este invalid sau a expirat. Cere celuilalt dispozitiv să genereze unul nou.',
        'identity_locked' => 'Identitatea dispozitivului tău este blocată. Deblochează aplicația și încearcă din nou.',
    ],
];
