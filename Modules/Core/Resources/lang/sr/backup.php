<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Ova aplikacija ne može da preda datoteku tvom uređaju, pa se šifrovana rezervna kopija pravi u aplikaciji za računar. Upari ovaj uređaj da bi ostali usklađeni.',
        'unavailable' => 'Šifrovane rezervne kopije dostupne su u desktop verziji (SQLite). Na serverskoj bazi podataka koristi sopstvene alate te baze za rezervne kopije.',
        'intro' => 'Preuzmi kopiju cele baze podataka šifrovanu pristupnom frazom — bezbedno je držati je na spoljnom disku ili u oblaku jer je bez pristupne fraze nečitljiva (kvantno otporni XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Pristupna fraza',
        'confirm_passphrase' => 'Potvrdi pristupnu frazu',
        'keep_safe' => 'Čuvaj pristupnu frazu na sigurnom — bez nje rezervna kopija ne može da se vrati.',
        'submit' => 'Preuzmi šifrovanu rezervnu kopiju',
        'preparing' => 'Priprema…',
    ],

    'restore' => [
        'heading' => 'Vraćanje iz rezervne kopije',

        'intro_html' => 'Zameni trenutnu bazu podataka šifrovanom rezervnom kopijom. Datoteka se dešifruje i proverava pre bilo kakve promene, a snimak trenutnih podataka se prvo sačuva — ali ovo i dalje <strong class="text-slate-700 dark:text-slate-200">prebrisuje sve</strong>, pa je dodatno zaštićeno. Bićeš odjavljen, jer je i tvoja prijava u bazi podataka.',
        'restored' => 'Rezervna kopija je vraćena. Prijavite se korisničkim imenom i lozinkom koji su važili kada je napravljena.',
        'snapshot_saved_prefix' => 'Snimak tvojih prethodnih podataka sačuvan je u',
        'file_label' => 'Datoteka rezervne kopije (.enc) ili arhiva izvoza (.zip)',
        'uploading' => 'Otpremanje…',
        'passphrase' => 'Pristupna fraza',
        'confirm_prefix' => 'Upiši',
        'confirm_suffix' => 'za potvrdu',
        'submit' => 'Vrati (prebrisuje trenutne podatke)',
        'restoring' => 'Vraćanje…',
    ],

    'errors' => [
        'passphrase_min' => 'Koristi pristupnu frazu od najmanje :min znak.|Koristi pristupnu frazu od najmanje :min znaka.|Koristi pristupnu frazu od najmanje :min znakova.',
        'passphrase_mismatch' => 'Dve pristupne fraze se ne poklapaju.',
        'download_sqlite_only' => 'Šifrovano preuzimanje dostupno je samo u SQLite verziji.',
        'create_failed' => 'Rezervna kopija nije mogla da se napravi: :message',
        'confirm_phrase' => 'Upiši :phrase za potvrdu — ovo zamenjuje tvoje trenutne podatke.',
        'choose_file' => 'Izaberi iz čega da se vrati: datoteku .enc sa rezervnom kopijom ili arhivu .zip koju je zapisao izvoz jednim klikom.',
        'upload_failed' => 'Otpremanje datoteke nije završeno. Možda je prevelika za ovaj uređaj — vraćanje u računarskoj aplikaciji prihvata veću rezervnu kopiju.',
        'enter_passphrase' => 'Unesi pristupnu frazu kojom je rezervna kopija šifrovana.',
        'unreadable' => 'Otpremljena datoteka nije mogla da se pročita. Pokušaj ponovo.',
        'restore_wrong_passphrase' => 'Ta lozinka nije otvorila ovu rezervnu kopiju i ništa nije promenjeno. Ukucaj je ponovo i pokušaj opet. Ako je sigurno ispravna, datoteka je izmenjena nakon što je nastala — tada vrati iz druge kopije.',
        'restore_not_a_backup' => 'Ova datoteka ne sadrži rezervnu kopiju Beatraxa, pa nema šta da se vrati i ništa nije promenjeno. Izaberi datoteku .enc koju je aplikacija zapisala kad je kopija napravljena ili arhivu .zip koju je zapisao izvoz jednim klikom.',
        'restore_contents_unreadable' => 'Rezervna kopija se otvorila, ali baza podataka u njoj je oštećena, pa nije vraćena i ništa nije promenjeno. Vrati iz starije rezervne kopije.',
        'restore_could_not_read' => 'Datoteku rezervne kopije nije bilo moguće pročitati, pa vraćanje nije izvršeno i ništa nije promenjeno. Proveri da li uređaj ima slobodnog prostora i pokušaj ponovo.',
        'restore_not_supported' => 'Vraćanje radi u izdanju koje drži podatke u jednoj datoteci, a ovo nije takvo, pa ništa nije promenjeno. Kod serverske baze koristi njene sopstvene alate za vraćanje.',
        'restore_failed' => 'Vraćanje nije izvršeno i ništa nije promenjeno. Pokušaj ponovo — ako i dalje ne uspeva, dnevnik aplikacije beleži šta ga je zaustavilo.',
    ],
];
