<?php

declare(strict_types=1);

return [
    'download' => [
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

        'intro_html' => 'Zameni trenutnu bazu podataka šifrovanom rezervnom kopijom. Datoteka se dešifruje i proverava pre bilo kakve promene, a snimak trenutnih podataka se prvo sačuva — ali ovo i dalje <strong class="text-slate-700 dark:text-slate-200">prebrisuje sve</strong>, pa je dodatno zaštićeno.',
        'restored' => 'Vraćeno. Ponovo učitaj aplikaciju da vidiš vraćene podatke.',
        'snapshot_saved_prefix' => 'Snimak tvojih prethodnih podataka sačuvan je u',
        'file_label' => 'Šifrovana rezervna kopija (.enc)',
        'uploading' => 'Otpremanje…',
        'passphrase' => 'Pristupna fraza',
        'confirm_prefix' => 'Upiši',
        'confirm_suffix' => 'za potvrdu',
        'submit' => 'Vrati (prebrisuje trenutne podatke)',
        'restoring' => 'Vraćanje…',
    ],

    'errors' => [
        'passphrase_min' => 'Koristi pristupnu frazu od najmanje :min znakova.',
        'passphrase_mismatch' => 'Dve pristupne fraze se ne poklapaju.',
        'download_sqlite_only' => 'Šifrovano preuzimanje dostupno je samo u SQLite verziji.',
        'create_failed' => 'Rezervna kopija nije mogla da se napravi: :message',
        'confirm_phrase' => 'Upiši :phrase za potvrdu — ovo zamenjuje tvoje trenutne podatke.',
        'choose_file' => 'Izaberi šifrovanu datoteku rezervne kopije (.enc) za vraćanje.',
        'enter_passphrase' => 'Unesi pristupnu frazu kojom je rezervna kopija šifrovana.',
        'unreadable' => 'Otpremljena datoteka nije mogla da se pročita. Pokušaj ponovo.',
    ],
];
