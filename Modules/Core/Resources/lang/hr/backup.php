<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Ovaj telefon ne može spremiti datoteku koju mu aplikacija preda, pa se šifrirana sigurnosna kopija radi u aplikaciji za računalo. Upari ovaj uređaj kako bi ostali usklađeni.',
        'unavailable' => 'Šifrirane sigurnosne kopije dostupne su u desktop verziji (SQLite). Na poslužiteljskoj bazi podataka koristi vlastite alate te baze za sigurnosno kopiranje.',
        'intro' => 'Preuzmi kopiju cijele baze podataka šifriranu pristupnom frazom — sigurno ju je držati na vanjskom disku ili u oblaku jer je bez pristupne fraze nečitljiva (kvantno otporni XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Pristupna fraza',
        'confirm_passphrase' => 'Potvrdi pristupnu frazu',
        'keep_safe' => 'Čuvaj pristupnu frazu na sigurnom — bez nje se sigurnosna kopija ne može vratiti.',
        'submit' => 'Preuzmi šifriranu sigurnosnu kopiju',
        'preparing' => 'Priprema…',
    ],

    'restore' => [
        'heading' => 'Vraćanje iz sigurnosne kopije',

        'intro_html' => 'Zamijeni trenutnu bazu podataka šifriranom sigurnosnom kopijom. Datoteka se dešifrira i provjerava prije bilo kakve promjene, a snimka trenutnih podataka sprema se prije toga — no ovo i dalje <strong class="text-slate-700 dark:text-slate-200">prebrisuje sve</strong>, pa je dodatno zaštićeno. Bit ćeš odjavljen jer je i tvoja prijava u bazi podataka.',
        'restored' => 'Vraćeno. Ponovno učitaj aplikaciju da vidiš vraćene podatke.',
        'snapshot_saved_prefix' => 'Snimka tvojih prethodnih podataka spremljena je u',
        'file_label' => 'Šifrirana sigurnosna kopija (.enc)',
        'uploading' => 'Učitavanje…',
        'passphrase' => 'Pristupna fraza',
        'confirm_prefix' => 'Upiši',
        'confirm_suffix' => 'za potvrdu',
        'submit' => 'Vrati (prebrisuje trenutne podatke)',
        'restoring' => 'Vraćanje…',
    ],

    'errors' => [
        'passphrase_min' => 'Koristi pristupnu frazu od najmanje :min znak.|Koristi pristupnu frazu od najmanje :min znaka.|Koristi pristupnu frazu od najmanje :min znakova.',
        'passphrase_mismatch' => 'Dvije pristupne fraze se ne podudaraju.',
        'download_sqlite_only' => 'Šifrirano preuzimanje dostupno je samo u SQLite verziji.',
        'create_failed' => 'Sigurnosnu kopiju nije bilo moguće stvoriti: :message',
        'confirm_phrase' => 'Upiši :phrase za potvrdu — ovo zamjenjuje tvoje trenutne podatke.',
        'choose_file' => 'Odaberi šifriranu datoteku sigurnosne kopije (.enc) za vraćanje.',
        'upload_failed' => 'Datoteka nije do kraja učitana. Možda je prevelika za ovaj uređaj — vraćanje u stolnoj aplikaciji prihvaća veću sigurnosnu kopiju.',
        'enter_passphrase' => 'Unesi pristupnu frazu kojom je sigurnosna kopija šifrirana.',
        'unreadable' => 'Učitanu datoteku nije bilo moguće pročitati. Pokušaj ponovno.',
    ],
];
