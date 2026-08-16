<?php

declare(strict_types=1);

return [
    'download' => [
        'unavailable' => 'Šifruotos atsarginės kopijos veikia darbalaukio (SQLite) versijoje. Serverio duomenų bazėje naudok pačios duomenų bazės atsarginių kopijų įrankius.',
        'intro' => 'Atsisiųsk slaptafraze užšifruotą visos savo duomenų bazės kopiją — ją saugu laikyti išoriniame diske ar debesijos saugykloje, nes be slaptafrazės jos perskaityti neįmanoma (kvantams atsparus XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Slaptafrazė',
        'confirm_passphrase' => 'Patvirtink slaptafrazę',
        'keep_safe' => 'Saugok slaptafrazę — be jos atsarginės kopijos atkurti neįmanoma.',
        'submit' => 'Atsisiųsti šifruotą atsarginę kopiją',
        'preparing' => 'Ruošiama…',
    ],

    'restore' => [
        'heading' => 'Atkurti iš atsarginės kopijos',

        'intro_html' => 'Pakeisk dabartinę duomenų bazę šifruota atsargine kopija. Failas iššifruojamas ir patikrinamas prieš keičiant bet ką, o dabartinių duomenų momentinė kopija išsaugoma pirmiausia — bet tai vis tiek <strong class="text-slate-700 dark:text-slate-200">perrašo viską</strong>, todėl veiksmas yra apsaugotas.',
        'restored' => 'Atkurta. Įkelk programėlę iš naujo, kad matytum atkurtus duomenis.',
        'snapshot_saved_prefix' => 'Ankstesnių duomenų momentinė kopija išsaugota į',
        'file_label' => 'Šifruota atsarginė kopija (.enc)',
        'uploading' => 'Įkeliama…',
        'passphrase' => 'Slaptafrazė',
        'confirm_prefix' => 'Įvesk',
        'confirm_suffix' => 'kad patvirtintum',
        'submit' => 'Atkurti (perrašo dabartinius duomenis)',
        'restoring' => 'Atkuriama…',
    ],

    'errors' => [
        'passphrase_min' => 'Naudok bent :min simbolių ilgio slaptafrazę.',
        'passphrase_mismatch' => 'Abi slaptafrazės nesutampa.',
        'download_sqlite_only' => 'Šifruotas atsisiuntimas veikia tik SQLite versijoje.',
        'create_failed' => 'Nepavyko sukurti atsarginės kopijos: :message',
        'confirm_phrase' => 'Įvesk :phrase, kad patvirtintum — tai pakeis dabartinius tavo duomenis.',
        'choose_file' => 'Pasirink šifruotos atsarginės kopijos failą (.enc), kurį nori atkurti.',
        'enter_passphrase' => 'Įvesk slaptafrazę, kuria atsarginė kopija buvo užšifruota.',
        'unreadable' => 'Įkelto failo perskaityti nepavyko. Bandyk dar kartą.',
    ],
];
