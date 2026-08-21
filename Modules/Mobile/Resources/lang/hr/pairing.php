<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Upareni uređaj',
    'page_title' => 'Upari uređaj',

    'scan_heading' => 'Upari ovaj uređaj',
    'scan_subtitle' => 'Usmjeri kameru na kod prikazan na drugom uređaju.',
    'camera_permission_pending' => 'Pristup kameri je isključen. Dopusti ga Beatraxu u postavkama uređaja pa pokušaj ponovno.',
    'open_camera' => 'Otvori kameru',
    'opening_camera' => 'Čekanje pristupa kameri…',
    'close_camera' => 'Zatvori kameru',
    'viewfinder_aria' => 'Tražilo kamere — usmjeri ga na kod na drugom uređaju',
    'viewfinder_idle' => 'Kamera je isključena. Otvori je da skeniraš kod prikazan na drugom uređaju.',
    'scan_prompt' => 'Skeniraj kod na drugom uređaju',
    'enter_code_instead' => 'Umjesto toga upiši kod',

    'enter_heading' => 'Upiši kod',
    'camera_off' => 'Pristup kameri je isključen. Umjesto toga upiši kod s drugog uređaja.',
    'word_code_aria' => 'Upiši kod u riječima s drugog uređaja',
    'submit_code' => 'Pošalji kod',
    'cancel' => 'Odustani',

    'confirm_heading' => 'Usporedi ove riječi s drugim uređajem',
    'safety_words_aria' => 'Riječi sigurnosnog broja: :words',
    'confirm_body' => 'Oba uređaja moraju prikazivati potpuno iste riječi. Ako se razlikuju, dodirni Odustani — možda je u tijeku napad posrednika.',
    'awaiting_peer' => 'Čekanje potvrde s drugog uređaja...',
    'confirm_match' => 'Potvrdi — podudaraju se',

    'success_heading' => 'Uređaj je uparen',
    'success_body' => 'Ovaj uređaj je sada pouzdan. Podaci će se sinkronizirati čim se povežeš.',
    'done' => 'Gotovo',

    'errors' => [
        'relay_unreachable' => 'Nije moguće doprijeti do drugog uređaja. Provjeri jesu li oba na istoj mreži i je li sinkronizacija uključena na računalu.',
        'invalid_code' => 'Ovaj kod nije ispravan ili je istekao. Zatraži da drugi uređaj izradi novi.',
        'code_not_accepted' => 'Nijedan uređaj na ovoj mreži nije prihvatio taj kôd. Provjeri kôd i prikazuje li ga drugi uređaj još uvijek.',
        'rate_limited' => 'Previše pokušaja. Pričekaj minutu i pokušaj ponovno.',
        'identity_locked' => 'Identitet tvojeg uređaja je zaključan. Otključaj aplikaciju pa pokušaj ponovno.',
        'identity_needs_lock' => 'Najprije postavite zaključavanje aplikacije — ono štiti identitet vašeg uređaja.',
        'safety_number_changed' => 'Drugi se uređaj promijenio dok si uspoređivao. Prije potvrde ponovno provjeri riječi u nastavku.',
    ],
];
