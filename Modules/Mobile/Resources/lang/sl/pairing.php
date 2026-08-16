<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Seznanjena naprava',
    'page_title' => 'Seznani napravo',

    'scan_heading' => 'Seznani to napravo',
    'scan_subtitle' => 'Kamero usmeri v kodo, prikazano na drugi napravi.',
    'camera_permission_pending' => 'Dostop do kamere je izklopljen. Dovoli ga Beatraxu v nastavitvah naprave in poskusi znova.',
    'open_camera' => 'Odpri kamero',
    'opening_camera' => 'Čakanje na dostop do kamere…',
    'close_camera' => 'Zapri kamero',
    'viewfinder_aria' => 'Iskalo kamere — usmeri ga v kodo na drugi napravi',
    'viewfinder_idle' => 'Kamera je izklopljena. Odpri jo, da poskeniraš kodo, prikazano na drugi napravi.',
    'scan_prompt' => 'Poskeniraj kodo na drugi napravi',
    'enter_code_instead' => 'Namesto tega vnesi kodo',

    'enter_heading' => 'Vnesi kodo',
    'camera_off' => 'Dostop do kamere je izklopljen. Namesto tega vnesi kodo z druge naprave.',
    'word_code_aria' => 'Vnesi besedno kodo z druge naprave',
    'submit_code' => 'Pošlji kodo',
    'cancel' => 'Prekliči',

    'confirm_heading' => 'Primerjaj te besede z drugo napravo',
    'safety_words_aria' => 'Besede varnostne številke: :words',
    'confirm_body' => 'Obe napravi morata prikazati popolnoma enake besede. Če se razlikujeta, se dotakni Prekliči — morda poteka napad vmesnega člena.',
    'awaiting_peer' => 'Čakanje na potrditev druge naprave...',
    'confirm_match' => 'Potrdi — ujemata se',

    'success_heading' => 'Naprava je seznanjena',
    'success_body' => 'Tej napravi je zdaj zaupano. Podatki se bodo sinhronizirali, ko se povežeš.',
    'done' => 'Končano',

    'errors' => [
        'relay_unreachable' => 'Druge naprave ni mogoče doseči. Preveri, ali sta obe v istem omrežju in ali je sinhronizacija na namizju vklopljena.',
        'import_needs_qr' => 'Za uvoz poskeniraj kodo QR, prikazano na drugi napravi.',
        'invalid_code' => 'Ta koda ni veljavna ali je potekla. Prosi drugo napravo, naj ustvari novo.',
        'identity_locked' => 'Identiteta tvoje naprave je zaklenjena. Odkleni aplikacijo in poskusi znova.',
    ],
];
