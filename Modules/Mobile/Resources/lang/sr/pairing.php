<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Upareni uređaj',
    'page_title' => 'Upari uređaj',

    'scan_heading' => 'Upari ovaj uređaj',
    'scan_subtitle' => 'Usmeri kameru na kod prikazan na drugom uređaju.',
    'camera_permission_pending' => 'Pristup kameri je isključen. Dozvoli ga Beatraxu u podešavanjima uređaja pa probaj ponovo.',
    'open_camera' => 'Otvori kameru',
    'opening_camera' => 'Čekanje pristupa kameri…',
    'close_camera' => 'Zatvori kameru',
    'viewfinder_aria' => 'Tražilo kamere — usmeri ga na kod na drugom uređaju',
    'viewfinder_idle' => 'Kamera je isključena. Otvori je da skeniraš kod prikazan na drugom uređaju.',
    'scan_prompt' => 'Skeniraj kod na drugom uređaju',
    'enter_code_instead' => 'Umesto toga unesi kod',

    'enter_heading' => 'Unesi kod',
    'camera_off' => 'Pristup kameri je isključen. Umesto toga unesi kod sa drugog uređaja.',
    'camera_off_no_search' => 'Pristup kameri je isključen, a traženje drugog uređaja na mreži na iPhone-u još ne radi — pa uneti kôd nema čime da ga nađe. Ponovo uključi pristup kameri za Beatrax u podešavanjima uređaja i skeniraj kôd sa drugog uređaja.',
    'no_search' => 'Traženje drugog uređaja na mreži na iPhone-u još ne radi, pa uneti kôd nema šta da nađe. Umesto toga skeniraj kôd kamerom — kamera ne mora da pretražuje mrežu.',
    'word_code_aria' => 'Unesi kod u rečima sa drugog uređaja',
    'submit_code' => 'Pošalji kod',
    'cancel' => 'Otkaži',
    'skip_import' => 'Nastavi bez uvoza',

    'confirm_heading' => 'Uporedi ove reči sa drugim uređajem',
    'safety_words_aria' => 'Reči bezbednosnog broja: :words',
    'confirm_body' => 'Oba uređaja moraju da prikazuju potpuno iste reči. Ako se razlikuju, dodirni Otkaži — možda je u toku napad posrednika.',
    'awaiting_peer' => 'Čekanje potvrde sa drugog uređaja...',
    'confirm_match' => 'Potvrdi — poklapaju se',

    'success_heading' => 'Uređaj je uparen',
    'success_body' => 'Ovaj uređaj je sada pouzdan. Podaci će se sinhronizovati čim se povežeš.',
    'done' => 'Gotovo',

    'errors' => [
        'relay_unreachable' => 'Nije moguće doći do drugog uređaja. Proveri da li su oba na istoj mreži i da li je sinhronizacija uključena na računaru.',
        'no_road_home' => 'Ovaj uređaj ne može da pretražuje mrežu, a kod koji si skenirao ne sadrži adresu drugog uređaja. Zatraži novi kod i skeniraj njega.',
        'invalid_code' => 'Ovaj kod nije ispravan ili je istekao. Zatraži da drugi uređaj napravi novi.',
        'code_incomplete' => 'Ovaj kod nije potpun. Uporedi ga sa drugim uređajem i unesi ga u celosti.',
        'code_not_accepted' => 'Nijedan uređaj na ovoj mreži nije prihvatio taj kôd. Proveri kôd i da li ga drugi uređaj još uvek prikazuje.',
        'no_peer_answered' => 'Ništa na ovoj mreži nije odgovorilo na taj kôd. Proveri da li sinhronizacija radi na drugom uređaju ili skeniraj njegov kôd kamerom — kamera ne mora da pretražuje mrežu.',
        'no_peer_answered_ios' => 'Ništa na ovoj mreži nije odgovorilo na taj kôd. Traženje drugog uređaja na mreži na iPhone-u još ne radi, pa skeniraj njegov kôd kamerom.',
        'no_peer_answered_camera_off' => 'Ništa na ovoj mreži nije odgovorilo na taj kôd. Traženje drugog uređaja na mreži na iPhone-u još ne radi, a pristup kameri je isključen — zato ponovo uključi pristup kameri za Beatrax u podešavanjima uređaja i skeniraj kôd sa drugog uređaja.',
        'rate_limited' => 'Previše pokušaja. Sačekaj minut i pokušaj ponovo.',
        'identity_locked' => 'Identitet tvog uređaja je zaključan. Otključaj aplikaciju pa probaj ponovo.',
        'identity_needs_lock' => 'Prvo podesite zaključavanje aplikacije — ono štiti identitet vašeg uređaja.',
        'safety_number_changed' => 'Drugi uređaj se promenio dok si upoređivao. Pre potvrde ponovo proveri reči ispod.',
    ],
];
