<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Ta aplikacija ne more predati datoteke tvoji napravi, zato šifrirano varnostno kopijo ustvariš v namizni aplikaciji. Seznanite to napravo, da ostaneta usklajeni.',
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
        'restored' => 'Varnostna kopija je obnovljena. Prijavite se z uporabniškim imenom in geslom, ki sta veljala ob njeni izdelavi.',
        'snapshot_saved_prefix' => 'Posnetek tvojih prejšnjih podatkov je shranjen v',
        'file_label' => 'Datoteka varnostne kopije (.enc) ali arhiv izvoza (.zip)',
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
        'choose_file' => 'Izberi, iz česa naj se obnovi: datoteko .enc z varnostno kopijo ali arhiv .zip, ki ga je zapisal izvoz z enim klikom.',
        'upload_failed' => 'Datoteka ni bila naložena do konca. Morda je prevelika za to napravo — obnovitev v namizni aplikaciji sprejme večjo varnostno kopijo.',
        'enter_passphrase' => 'Vnesi geslo, s katerim je bila varnostna kopija šifrirana.',
        'unreadable' => 'Naložene datoteke ni bilo mogoče prebrati. Poskusi znova.',
        'restore_wrong_passphrase' => 'To geslo ni odprlo te varnostne kopije in nič ni bilo spremenjeno. Vnesi ga znova in poskusi še enkrat. Če je zagotovo pravilno, je bila datoteka po nastanku spremenjena — takrat obnovi iz druge kopije.',
        'restore_not_a_backup' => 'Ta datoteka ne vsebuje varnostne kopije Beatraxa, zato ni česa obnoviti in nič ni bilo spremenjeno. Izberi datoteko .enc, ki jo je aplikacija zapisala ob izdelavi kopije, ali arhiv .zip, ki ga je zapisal izvoz z enim klikom.',
        'restore_contents_unreadable' => 'Varnostna kopija se je odprla, a je zbirka podatkov v njej poškodovana, zato ni bila obnovljena in nič ni bilo spremenjeno. Obnovi iz starejše varnostne kopije.',
        'restore_could_not_read' => 'Datoteke varnostne kopije ni bilo mogoče prebrati, zato obnovitev ni tekla in nič ni bilo spremenjeno. Preveri, ali ima naprava prosti prostor, in poskusi znova.',
        'restore_not_supported' => 'Obnovitev deluje v izdaji, ki hrani podatke v eni sami datoteki, ta pa to ni, zato nič ni bilo spremenjeno. Pri strežniški zbirki podatkov uporabi njena lastna orodja za obnovitev.',
        'restore_failed' => 'Obnovitev ni tekla in nič ni bilo spremenjeno. Poskusi znova — če še naprej spodleti, dnevnik aplikacije zabeleži, kaj jo je ustavilo.',
    ],
];
