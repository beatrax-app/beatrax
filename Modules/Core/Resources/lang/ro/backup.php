<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Acest telefon nu poate salva un fișier pe care aplicația i-l predă, așa că backupul criptat se face în aplicația de desktop. Asociază acest dispozitiv pentru a le păstra sincronizate.',
        'unavailable' => 'Copiile de rezervă criptate sunt disponibile în versiunea desktop (SQLite). Pe o bază de date pe server, folosește instrumentele proprii de copiere de rezervă ale bazei de date.',
        'intro' => 'Descarcă o copie a întregii tale baze de date, criptată cu o frază de acces — o poți păstra în siguranță pe un disc extern sau în cloud, pentru că fără fraza de acces este ilizibilă (XChaCha20-Poly1305 + Argon2id, rezistente cuantic).',
        'passphrase' => 'Frază de acces',
        'confirm_passphrase' => 'Confirmă fraza de acces',
        'keep_safe' => 'Păstrează fraza de acces în siguranță — fără ea copia de rezervă nu poate fi recuperată.',
        'submit' => 'Descarcă copia de rezervă criptată',
        'preparing' => 'Se pregătește…',
    ],

    'restore' => [
        'heading' => 'Restaurează dintr-o copie de rezervă',

        'intro_html' => 'Înlocuiește baza de date actuală cu o copie de rezervă criptată. Fișierul este decriptat și verificat înainte să se schimbe ceva, iar un instantaneu al datelor tale actuale este salvat mai întâi — dar tot <strong class="text-slate-700 dark:text-slate-200">suprascrie totul</strong>, așa că acțiunea este protejată. Vei fi deconectat, deoarece și autentificarea ta se află în baza de date.',
        'restored' => 'Copia de rezervă a fost restaurată. Autentifică-te cu numele de utilizator și parola valabile când a fost creată.',
        'snapshot_saved_prefix' => 'Un instantaneu al datelor tale anterioare a fost salvat în',
        'file_label' => 'Copie de rezervă criptată (.enc)',
        'uploading' => 'Se încarcă…',
        'passphrase' => 'Frază de acces',
        'confirm_prefix' => 'Scrie',
        'confirm_suffix' => 'pentru a confirma',
        'submit' => 'Restaurează (suprascrie datele actuale)',
        'restoring' => 'Se restaurează…',
    ],

    'errors' => [
        'passphrase_min' => 'Folosește o frază de acces de cel puțin :min caracter.|Folosește o frază de acces de cel puțin :min caractere.|Folosește o frază de acces de cel puțin :min de caractere.',
        'passphrase_mismatch' => 'Cele două fraze de acces nu coincid.',
        'download_sqlite_only' => 'Descărcarea criptată este disponibilă doar în versiunea SQLite.',
        'create_failed' => 'Copia de rezervă nu a putut fi creată: :message',
        'confirm_phrase' => 'Scrie :phrase pentru a confirma — asta îți înlocuiește datele actuale.',
        'choose_file' => 'Alege un fișier de copie de rezervă criptat (.enc) pentru restaurare.',
        'upload_failed' => 'Fișierul nu s-a încărcat complet. Poate fi prea mare pentru acest dispozitiv — restaurarea în aplicația de desktop acceptă o copie mai mare.',
        'enter_passphrase' => 'Introdu fraza de acces cu care a fost criptată copia de rezervă.',
        'unreadable' => 'Fișierul încărcat nu a putut fi citit. Încearcă din nou.',
    ],
];
