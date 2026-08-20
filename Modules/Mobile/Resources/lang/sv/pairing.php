<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Parkopplad enhet',
    'page_title' => 'Parkoppla en enhet',

    'scan_heading' => 'Parkoppla den här enheten',
    'scan_subtitle' => 'Rikta kameran mot koden som visas på den andra enheten.',
    'camera_permission_pending' => 'Kameraåtkomsten är avstängd. Tillåt den för Beatrax i enhetens inställningar och försök igen.',
    'open_camera' => 'Öppna kameran',
    'opening_camera' => 'Väntar på kameraåtkomst…',
    'close_camera' => 'Stäng kameran',
    'viewfinder_aria' => 'Kamerans sökare — rikta den mot koden på din andra enhet',
    'viewfinder_idle' => 'Kameran är avstängd. Öppna den för att skanna koden som visas på din andra enhet.',
    'scan_prompt' => 'Skanna koden på din andra enhet',
    'enter_code_instead' => 'Ange koden i stället',

    'enter_heading' => 'Ange koden',
    'camera_off' => 'Kameraåtkomsten är avstängd. Ange koden från den andra enheten i stället.',
    'word_code_aria' => 'Ange ordkoden från den andra enheten',
    'submit_code' => 'Skicka koden',
    'cancel' => 'Avbryt',

    'confirm_heading' => 'Jämför de här orden med den andra enheten',
    'safety_words_aria' => 'Ord för säkerhetsnummer: :words',
    'confirm_body' => 'Båda enheterna måste visa exakt samma ord. Om de skiljer sig åt, tryck på Avbryt — en man-in-the-middle-attack kan pågå.',
    'awaiting_peer' => 'Väntar på att den andra enheten ska bekräfta...',
    'confirm_match' => 'Bekräfta — de stämmer överens',

    'success_heading' => 'Enheten är parkopplad',
    'success_body' => 'Den här enheten är nu betrodd. Dina data synkroniseras så snart du ansluter.',
    'done' => 'Klar',

    'errors' => [
        'relay_unreachable' => 'Det går inte att nå den andra enheten. Kontrollera att båda är på samma nätverk och att synkronisering är aktiverad på datorn.',
        'invalid_code' => 'Koden är ogiltig eller har gått ut. Låt den andra enheten skapa en ny.',
        'identity_locked' => 'Enhetens identitet är låst. Lås upp appen och försök igen.',
        'identity_needs_lock' => 'Ställ in applåset först — det skyddar enhetens identitet.',
    ],
];
