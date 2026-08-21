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
    'word_code_aria' => 'Unesi kod u rečima sa drugog uređaja',
    'submit_code' => 'Pošalji kod',
    'cancel' => 'Otkaži',

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
        'invalid_code' => 'Ovaj kod nije ispravan ili je istekao. Zatraži da drugi uređaj napravi novi.',
        'code_not_accepted' => 'Nijedan uređaj na ovoj mreži nije prihvatio taj kôd. Proveri kôd i da li ga drugi uređaj još uvek prikazuje.',
        'rate_limited' => 'Previše pokušaja. Sačekaj minut i pokušaj ponovo.',
        'identity_locked' => 'Identitet tvog uređaja je zaključan. Otključaj aplikaciju pa probaj ponovo.',
        'identity_needs_lock' => 'Prvo podesite zaključavanje aplikacije — ono štiti identitet vašeg uređaja.',
        'safety_number_changed' => 'Drugi uređaj se promenio dok si upoređivao. Pre potvrde ponovo proveri reči ispod.',
    ],
];
