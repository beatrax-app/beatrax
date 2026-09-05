<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Táto aplikácia nedokáže odovzdať súbor tvojmu zariadeniu, takže sa zašifrovaná záloha vytvára v aplikácii pre počítač. Spárujte toto zariadenie, aby zostali synchronizované.',
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

        'intro_html' => 'Nahradí tvoju súčasnú databázu šifrovanou zálohou. Súbor sa pred akoukoľvek zmenou dešifruje a skontroluje a najprv sa uloží snímka tvojich súčasných údajov — aj tak to však <strong class="text-slate-700 dark:text-slate-200">prepíše všetko</strong>, preto je tento krok zabezpečený. Budeš odhlásený, pretože aj tvoje prihlásenie je v databáze.',
        'restored' => 'Vaša záloha bola obnovená. Prihláste sa používateľským menom a heslom, ktoré platili v čase jej vytvorenia.',
        'snapshot_saved_prefix' => 'Snímka tvojich predchádzajúcich údajov bola uložená do',
        'file_label' => 'Súbor so zálohou (.enc) alebo archív exportu (.zip)',
        'uploading' => 'Nahráva sa…',
        'passphrase' => 'Prístupová fráza',
        'confirm_prefix' => 'Napíš',
        'confirm_suffix' => 'na potvrdenie',
        'submit' => 'Obnoviť (prepíše súčasné údaje)',
        'restoring' => 'Obnovuje sa…',
    ],

    'errors' => [
        'passphrase_min' => 'Použi prístupovú frázu s dĺžkou aspoň :min znak.|Použi prístupovú frázu s dĺžkou aspoň :min znaky.|Použi prístupovú frázu s dĺžkou aspoň :min znakov.',
        'passphrase_mismatch' => 'Zadané prístupové frázy sa nezhodujú.',
        'download_sqlite_only' => 'Šifrované sťahovanie je dostupné len vo verzii so SQLite.',
        'create_failed' => 'Zálohu sa nepodarilo vytvoriť: :message',
        'confirm_phrase' => 'Na potvrdenie napíš :phrase — nahradí to tvoje súčasné údaje.',
        'choose_file' => 'Vyber, z čoho sa má obnoviť: súbor .enc so zálohou alebo archív .zip, ktorý zapísal export jedným kliknutím.',
        'upload_failed' => 'Súbor sa nepodarilo úplne nahrať. Môže byť pre toto zariadenie príliš veľký — obnovenie v počítačovej aplikácii prijme väčšiu zálohu.',
        'enter_passphrase' => 'Zadaj prístupovú frázu, ktorou bola záloha zašifrovaná.',
        'unreadable' => 'Nahraný súbor sa nepodarilo prečítať. Skús to znova.',
        'restore_wrong_passphrase' => 'Táto prístupová fráza túto zálohu neotvorila a nič sa nezmenilo. Napíš ju znova a skús to ešte raz. Ak je určite správna, súbor bol po vytvorení zmenený — obnov teda z inej kópie.',
        'restore_not_a_backup' => 'Tento súbor neobsahuje žiadnu zálohu Beatrax, takže nie je čo obnovovať a nič sa nezmenilo. Vyber súbor .enc, ktorý aplikácia zapísala pri vytváraní zálohy, alebo archív .zip, ktorý zapísal export jedným kliknutím.',
        'restore_contents_unreadable' => 'Záloha sa otvorila, ale databáza vnútri je poškodená, takže sa neobnovila a nič sa nezmenilo. Obnov zo staršej zálohy.',
        'restore_could_not_read' => 'Súbor zálohy sa nepodarilo prečítať, takže obnova neprebehla a nič sa nezmenilo. Skontroluj, či má zariadenie voľné miesto, a skús to znova.',
        'restore_not_supported' => 'Obnova funguje vo verzii, ktorá drží dáta v jedinom súbore, a táto ňou nie je, takže sa nič nezmenilo. Pri serverovej databáze použi jej vlastné nástroje obnovy.',
        'restore_failed' => 'Obnova neprebehla a nič sa nezmenilo. Skús to znova — ak zlyháva ďalej, protokol aplikácie zaznamenáva, čo ju zastavilo.',
    ],
];
