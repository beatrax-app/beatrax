<?php

declare(strict_types=1);

return [
    'download' => [
        'unavailable' => 'Šifrované zálohy sú dostupné v desktopovej verzii (SQLite). Pri serverovej databáze použi vlastné zálohovacie nástroje danej databázy.',
        'intro' => 'Stiahni si kópiu celej databázy zašifrovanú prístupovou frázou — pokojne ju drž na externom disku alebo v cloude, bez frázy je nečitateľná (kvantovo odolné XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Prístupová fráza',
        'confirm_passphrase' => 'Potvrď prístupovú frázu',
        'keep_safe' => 'Prístupovú frázu si dobre ulož — bez nej sa záloha nedá obnoviť.',
        'submit' => 'Stiahnuť šifrovanú zálohu',
        'preparing' => 'Pripravuje sa…',
    ],

    'restore' => [
        'heading' => 'Obnovenie zo zálohy',

        'intro_html' => 'Nahradí tvoju súčasnú databázu šifrovanou zálohou. Súbor sa pred akoukoľvek zmenou dešifruje a skontroluje a najprv sa uloží snímka tvojich súčasných údajov — aj tak to však <strong class="text-slate-700 dark:text-slate-200">prepíše všetko</strong>, preto je tento krok zabezpečený.',
        'restored' => 'Obnovené. Znovu načítaj aplikáciu a uvidíš obnovené údaje.',
        'snapshot_saved_prefix' => 'Snímka tvojich predchádzajúcich údajov bola uložená do',
        'file_label' => 'Šifrovaná záloha (.enc)',
        'uploading' => 'Nahráva sa…',
        'passphrase' => 'Prístupová fráza',
        'confirm_prefix' => 'Napíš',
        'confirm_suffix' => 'na potvrdenie',
        'submit' => 'Obnoviť (prepíše súčasné údaje)',
        'restoring' => 'Obnovuje sa…',
    ],

    'errors' => [
        'passphrase_min' => 'Použi prístupovú frázu s dĺžkou aspoň :min znakov.',
        'passphrase_mismatch' => 'Zadané prístupové frázy sa nezhodujú.',
        'download_sqlite_only' => 'Šifrované sťahovanie je dostupné len vo verzii so SQLite.',
        'create_failed' => 'Zálohu sa nepodarilo vytvoriť: :message',
        'confirm_phrase' => 'Na potvrdenie napíš :phrase — nahradí to tvoje súčasné údaje.',
        'choose_file' => 'Vyber súbor šifrovanej zálohy (.enc), ktorý sa má obnoviť.',
        'enter_passphrase' => 'Zadaj prístupovú frázu, ktorou bola záloha zašifrovaná.',
        'unreadable' => 'Nahraný súbor sa nepodarilo prečítať. Skús to znova.',
    ],
];
