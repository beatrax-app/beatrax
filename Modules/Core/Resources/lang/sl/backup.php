<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Ta telefon ne more shraniti datoteke, ki mu jo aplikacija izroči, zato šifrirano varnostno kopijo naredite v namizni aplikaciji. Seznanite to napravo, da ostaneta usklajeni.',
        'unavailable' => 'Šifrirane varnostne kopije so na voljo v namizni različici (SQLite). Pri strežniški bazi podatkov uporabi orodja za varnostno kopiranje same baze.',
        'intro' => 'Prenesi z geslom šifrirano kopijo celotne baze podatkov — varno jo je hraniti na zunanjem disku ali v oblaku, ker je brez gesla neberljiva (kvantno odporen XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Geslo',
        'confirm_passphrase' => 'Potrdi geslo',
        'keep_safe' => 'Geslo shrani na varno — brez njega varnostne kopije ni mogoče obnoviti.',
        'submit' => 'Prenesi šifrirano varnostno kopijo',
        'preparing' => 'Pripravljanje…',
    ],

    'restore' => [
        'heading' => 'Obnovitev iz varnostne kopije',

        'intro_html' => 'Zamenjaj trenutno bazo podatkov s šifrirano varnostno kopijo. Datoteka se pred kakršno koli spremembo dešifrira in preveri, posnetek trenutnih podatkov pa se shrani vnaprej — a to kljub temu <strong class="text-slate-700 dark:text-slate-200">prepiše vse</strong>, zato je dodatno zaščiteno. Odjavljen boš, saj je tudi tvoja prijava v zbirki podatkov.',
        'restored' => 'Obnovljeno. Znova naloži aplikacijo, da vidiš obnovljene podatke.',
        'snapshot_saved_prefix' => 'Posnetek tvojih prejšnjih podatkov je shranjen v',
        'file_label' => 'Šifrirana varnostna kopija (.enc)',
        'uploading' => 'Nalaganje…',
        'passphrase' => 'Geslo',
        'confirm_prefix' => 'Vpiši',
        'confirm_suffix' => 'za potrditev',
        'submit' => 'Obnovi (prepiše trenutne podatke)',
        'restoring' => 'Obnavljanje…',
    ],

    'errors' => [
        'passphrase_min' => 'Uporabi geslo z vsaj :min znakom.|Uporabi geslo z vsaj :min znakoma.|Uporabi geslo z vsaj :min znaki.|Uporabi geslo z vsaj :min znaki.',
        'passphrase_mismatch' => 'Gesli se ne ujemata.',
        'download_sqlite_only' => 'Šifriran prenos je na voljo samo v različici SQLite.',
        'create_failed' => 'Varnostne kopije ni bilo mogoče ustvariti: :message',
        'confirm_phrase' => 'Vpiši :phrase za potrditev — to zamenja tvoje trenutne podatke.',
        'choose_file' => 'Izberi šifrirano datoteko varnostne kopije (.enc) za obnovitev.',
        'enter_passphrase' => 'Vnesi geslo, s katerim je bila varnostna kopija šifrirana.',
        'unreadable' => 'Naložene datoteke ni bilo mogoče prebrati. Poskusi znova.',
    ],
];
