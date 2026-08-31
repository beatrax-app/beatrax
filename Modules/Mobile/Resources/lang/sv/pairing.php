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
    'camera_off_no_search' => 'Kameraåtkomsten är avstängd, och att söka efter den andra enheten på nätverket fungerar inte på iPhone ännu — en kod du skriver in har därför inget att hitta den med. Slå på kameraåtkomsten igen för Beatrax i enhetens inställningar och skanna koden på den andra enheten.',
    'no_search' => 'Att söka efter den andra enheten på nätverket fungerar inte på iPhone ännu, så en kod du skriver in har inget att hitta. Skanna koden med kameran i stället — kameran behöver inte söka på nätverket.',
    'word_code_aria' => 'Ange ordkoden från den andra enheten',
    'submit_code' => 'Skicka koden',
    'cancel' => 'Avbryt',
    'skip_import' => 'Fortsätt utan att importera',

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
        'no_road_home' => 'Den här enheten kan inte söka på nätverket, och koden du skannade innehåller ingen adress till den andra enheten. Be den visa en ny kod och skanna den i stället.',
        'invalid_code' => 'Koden är ogiltig eller har gått ut. Låt den andra enheten skapa en ny.',
        'code_incomplete' => 'Koden är inte fullständig. Jämför den med den andra enheten och ange hela koden.',
        'code_not_accepted' => 'Ingen enhet i det här nätverket accepterade koden. Kontrollera koden och att den andra enheten fortfarande visar den.',
        'no_peer_answered' => 'Inget på det här nätverket svarade på koden. Kontrollera att synkronisering körs på den andra enheten, eller skanna dess kod med kameran — kameran behöver inte söka på nätverket.',
        'no_peer_answered_ios' => 'Inget på det här nätverket svarade på koden. Att söka efter den andra enheten på nätverket fungerar inte på iPhone ännu, så skanna dess kod med kameran.',
        'no_peer_answered_camera_off' => 'Inget på det här nätverket svarade på koden. Att söka efter den andra enheten på nätverket fungerar inte på iPhone ännu, och kameraåtkomsten är avstängd — slå därför på kameraåtkomsten igen för Beatrax i enhetens inställningar och skanna koden på den andra enheten.',
        'rate_limited' => 'För många försök. Vänta en minut och försök igen.',
        'identity_locked' => 'Enhetens identitet är låst. Lås upp appen och försök igen.',
        'identity_needs_lock' => 'Ställ in applåset först — det skyddar enhetens identitet.',
        'safety_number_changed' => 'Den andra enheten ändrades medan du jämförde. Kontrollera orden nedan igen innan du bekräftar.',
    ],
];
