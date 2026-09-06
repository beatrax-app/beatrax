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
    'camera_off_no_search' => 'Pristup kameri je isključen, a traženje drugog uređaja na mreži na iPhone-u još ne radi — pa ga unet kod sam ne može pronaći. U podešavanjima uređaja ponovo uključi pristup kameri za Beatrax i skeniraj kod prikazan na drugom uređaju ili pošalji kod ovde pa će te ovaj ekran pitati gde je.',
    'no_search' => 'Traženje drugog uređaja na mreži na iPhone-u još ne radi, pa ga unet kod sam ne može pronaći. Kod radije skeniraj kamerom — njoj traženje po mreži nije potrebno. Ako ne možeš da skeniraš, pošalji kod i ovaj ekran će te pitati gde je drugi uređaj.',
    'word_code_aria' => 'Unesi kod u rečima sa drugog uređaja',
    'initiator_address' => 'Gde je drugi uređaj?',
    'initiator_address_help' => 'Njegova adresa na ovoj mreži, kao host i port. Računar je prikazuje pod Uređaji i sinhronizacija. Kada je uneseš, ponovo pošalji kod.',
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
    'encryption_incomplete' => 'Uređaj je uparen, ali šifrovanje podataka sačuvanih na njemu nije dovršeno. Podaci se još ne čuvaju šifrovani.',
    'done' => 'Gotovo',

    'errors' => [
        'relay_unreachable' => 'Nije moguće doći do drugog uređaja. Proveri da li su oba na istoj mreži i da li je sinhronizacija uključena na računaru.',
        'no_road_home' => 'Ovaj uređaj ne može da pretražuje mrežu, a kod koji si skenirao ne sadrži adresu drugog uređaja. Zatraži novi kod i skeniraj njega.',
        'invalid_code' => 'Ovaj kod nije ispravan ili je istekao. Zatraži da drugi uređaj napravi novi.',
        'already_under_way' => 'Ovaj uređaj je taj kod već prihvatio i čeka potvrdu sa drugog uređaja. Ako ne stigne, zatraži novi kod i upotrebi njega.',
        'vouched_but_refused' => 'Drugi uređaj još uvek ima taj kod, ali ga ovaj uređaj nije mogao prihvatiti. Zatraži od njega novi kod i upotrebi njega.',
        'code_incomplete' => 'Ovaj kod nije potpun. Uporedi ga sa drugim uređajem i unesi ga u celosti.',
        'initiator_address_invalid' => 'To nije adresa koju ovaj uređaj može da pozove. Unesi je kao host i port, na primer 192.168.1.20:8100.',
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
