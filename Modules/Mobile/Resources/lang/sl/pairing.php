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
    'camera_off_no_search' => 'Dostop do kamere je izklopljen in iskanje druge naprave v omrežju na iPhonu še ne deluje — vnesena koda tako nima s čim priti do nje. Znova dovoli kamero za Beatrax v nastavitvah naprave in skeniraj kodo z druge naprave.',
    'no_search' => 'Iskanje druge naprave v omrežju na iPhonu še ne deluje, zato vnesena koda nima česa najti. Namesto tega skeniraj kodo s kamero — kameri ni treba iskati po omrežju.',
    'word_code_aria' => 'Vnesi besedno kodo z druge naprave',
    'submit_code' => 'Pošlji kodo',
    'cancel' => 'Prekliči',
    'skip_import' => 'Nadaljuj brez uvoza',

    'confirm_heading' => 'Primerjaj te besede z drugo napravo',
    'safety_words_aria' => 'Besede varnostne številke: :words',
    'confirm_body' => 'Obe napravi morata prikazati popolnoma enake besede. Če se razlikujeta, se dotakni Prekliči — morda poteka napad vmesnega člena.',
    'awaiting_peer' => 'Čakanje na potrditev druge naprave...',
    'confirm_match' => 'Potrdi — ujemata se',

    'success_heading' => 'Naprava je seznanjena',
    'success_body' => 'Tej napravi je zdaj zaupano. Podatki se bodo sinhronizirali, ko se povežeš.',
    'encryption_incomplete' => 'Naprava je seznanjena, vendar šifriranje podatkov, shranjenih v njej, ni bilo dokončano. Podatki še niso shranjeni šifrirano.',
    'done' => 'Končano',

    'errors' => [
        'relay_unreachable' => 'Druge naprave ni mogoče doseči. Preveri, ali sta obe v istem omrežju in ali je sinhronizacija na namizju vklopljena.',
        'no_road_home' => 'Ta naprava ne more iskati po omrežju, koda, ki si jo skeniral, pa ne vsebuje naslova druge naprave. Prosi jo za novo kodo in skeniraj to.',
        'invalid_code' => 'Ta koda ni veljavna ali je potekla. Prosi drugo napravo, naj ustvari novo.',
        'already_under_way' => 'Ta naprava je kodo že sprejela in čaka na potrditev druge naprave. Če ne pride, na njej ustvari novo kodo in uporabi tisto.',
        'vouched_but_refused' => 'Druga naprava kodo še ima, a je ta naprava ni mogla sprejeti. Na njej ustvari novo kodo in uporabi tisto.',
        'code_incomplete' => 'Ta koda ni popolna. Primerjaj jo z drugo napravo in jo vnesi v celoti.',
        'code_not_accepted' => 'Nobena naprava v tem omrežju ni sprejela te kode. Preveri kodo in ali jo druga naprava še vedno prikazuje.',
        'no_peer_answered' => 'Nič v tem omrežju ni odgovorilo na to kodo. Preveri, ali na drugi napravi teče sinhronizacija, ali pa skeniraj njeno kodo s kamero — kameri ni treba iskati po omrežju.',
        'no_peer_answered_ios' => 'Nič v tem omrežju ni odgovorilo na to kodo. Iskanje druge naprave v omrežju na iPhonu še ne deluje, zato skeniraj njeno kodo s kamero.',
        'no_peer_answered_camera_off' => 'Nič v tem omrežju ni odgovorilo na to kodo. Iskanje druge naprave v omrežju na iPhonu še ne deluje, dostop do kamere pa je izklopljen — zato znova dovoli kamero za Beatrax v nastavitvah naprave in skeniraj kodo z druge naprave.',
        'rate_limited' => 'Preveč poskusov. Počakaj minuto in poskusi znova.',
        'identity_locked' => 'Identiteta tvoje naprave je zaklenjena. Odkleni aplikacijo in poskusi znova.',
        'identity_needs_lock' => 'Najprej nastavi zaklepanje aplikacije — ščiti identiteto tvoje naprave.',
        'safety_number_changed' => 'Druga naprava se je med primerjanjem spremenila. Pred potrditvijo znova preveri spodnje besede.',
    ],
];
