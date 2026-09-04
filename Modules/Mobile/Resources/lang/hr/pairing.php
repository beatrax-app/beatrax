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
    'camera_off_no_search' => 'Pristup kameri je isključen, a traženje drugog uređaja na mreži na iPhoneu još ne radi — pa upisani kod nema čime doći do njega. Ponovno uključi pristup kameri za Beatrax u postavkama uređaja i skeniraj kod s drugog uređaja.',
    'no_search' => 'Traženje drugog uređaja na mreži na iPhoneu još ne radi, pa upisani kod nema što pronaći. Umjesto toga skeniraj kod kamerom — kamera ne treba pretraživati mrežu.',
    'word_code_aria' => 'Upiši kod u riječima s drugog uređaja',
    'submit_code' => 'Pošalji kod',
    'cancel' => 'Odustani',
    'skip_import' => 'Nastavi bez uvoza',

    'confirm_heading' => 'Usporedi ove riječi s drugim uređajem',
    'safety_words_aria' => 'Riječi sigurnosnog broja: :words',
    'confirm_body' => 'Oba uređaja moraju prikazivati potpuno iste riječi. Ako se razlikuju, dodirni Odustani — možda je u tijeku napad posrednika.',
    'awaiting_peer' => 'Čekanje potvrde s drugog uređaja...',
    'confirm_match' => 'Potvrdi — podudaraju se',

    'success_heading' => 'Uređaj je uparen',
    'success_body' => 'Ovaj uređaj je sada pouzdan. Podaci će se sinkronizirati čim se povežeš.',
    'encryption_incomplete' => 'Uređaj je uparen, no šifriranje podataka pohranjenih na njemu nije dovršeno. Podaci se još ne pohranjuju šifrirani.',
    'done' => 'Gotovo',

    'errors' => [
        'relay_unreachable' => 'Nije moguće doprijeti do drugog uređaja. Provjeri jesu li oba na istoj mreži i je li sinkronizacija uključena na računalu.',
        'no_road_home' => 'Ovaj uređaj ne može pretraživati mrežu, a kod koji si skenirao ne sadrži adresu drugog uređaja. Zatraži novi kod i skeniraj njega.',
        'invalid_code' => 'Ovaj kod nije ispravan ili je istekao. Zatraži da drugi uređaj izradi novi.',
        'code_incomplete' => 'Ovaj kod nije potpun. Usporedi ga s drugim uređajem i unesi ga u cijelosti.',
        'code_not_accepted' => 'Nijedan uređaj na ovoj mreži nije prihvatio taj kôd. Provjeri kôd i prikazuje li ga drugi uređaj još uvijek.',
        'no_peer_answered' => 'Ništa na ovoj mreži nije odgovorilo na taj kod. Provjeri radi li sinkronizacija na drugom uređaju ili skeniraj njegov kod kamerom — kamera ne treba pretraživati mrežu.',
        'no_peer_answered_ios' => 'Ništa na ovoj mreži nije odgovorilo na taj kod. Traženje drugog uređaja na mreži na iPhoneu još ne radi, pa skeniraj njegov kod kamerom.',
        'no_peer_answered_camera_off' => 'Ništa na ovoj mreži nije odgovorilo na taj kod. Traženje drugog uređaja na mreži na iPhoneu još ne radi, a pristup kameri je isključen — zato ponovno uključi pristup kameri za Beatrax u postavkama uređaja i skeniraj kod s drugog uređaja.',
        'rate_limited' => 'Previše pokušaja. Pričekaj minutu i pokušaj ponovno.',
        'identity_locked' => 'Identitet tvojeg uređaja je zaključan. Otključaj aplikaciju pa pokušaj ponovno.',
        'identity_needs_lock' => 'Najprije postavite zaključavanje aplikacije — ono štiti identitet vašeg uređaja.',
        'safety_number_changed' => 'Drugi se uređaj promijenio dok si uspoređivao. Prije potvrde ponovno provjeri riječi u nastavku.',
    ],
];
