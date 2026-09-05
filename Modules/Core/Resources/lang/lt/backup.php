<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Ši programa negali perduoti failo tavo įrenginiui, todėl užšifruota atsarginė kopija kuriama kompiuterio programoje. Susiek šį įrenginį, kad abu liktų sinchronizuoti.',
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

        'intro_html' => 'Pakeisk dabartinę duomenų bazę šifruota atsargine kopija. Failas iššifruojamas ir patikrinamas prieš keičiant bet ką, o dabartinių duomenų momentinė kopija išsaugoma pirmiausia — bet tai vis tiek <strong class="text-slate-700 dark:text-slate-200">perrašo viską</strong>, todėl veiksmas yra apsaugotas. Būsi atjungtas, nes tavo prisijungimas taip pat yra duomenų bazėje.',
        'restored' => 'Atsarginė kopija atkurta. Prisijunkite naudotojo vardu ir slaptažodžiu, galiojusiais ją kuriant.',
        'snapshot_saved_prefix' => 'Ankstesnių duomenų momentinė kopija išsaugota į',
        'file_label' => 'Atsarginės kopijos failas (.enc) arba eksporto archyvas (.zip)',
        'uploading' => 'Įkeliama…',
        'passphrase' => 'Slaptafrazė',
        'confirm_prefix' => 'Įvesk',
        'confirm_suffix' => 'kad patvirtintum',
        'submit' => 'Atkurti (perrašo dabartinius duomenis)',
        'restoring' => 'Atkuriama…',
    ],

    'errors' => [
        'passphrase_min' => 'Naudok bent :min simbolio ilgio slaptafrazę.|Naudok bent :min simbolių ilgio slaptafrazę.|Naudok bent :min simbolių ilgio slaptafrazę.',
        'passphrase_mismatch' => 'Abi slaptafrazės nesutampa.',
        'download_sqlite_only' => 'Šifruotas atsisiuntimas veikia tik SQLite versijoje.',
        'create_failed' => 'Nepavyko sukurti atsarginės kopijos: :message',
        'confirm_phrase' => 'Įvesk :phrase, kad patvirtintum — tai pakeis dabartinius tavo duomenis.',
        'choose_file' => 'Pasirink, iš ko atkurti: .enc atsarginės kopijos failą ar .zip archyvą, kurį įrašė eksportas vienu paspaudimu.',
        'upload_failed' => 'Failas nebuvo įkeltas iki galo. Jis gali būti per didelis šiam įrenginiui — atkūrimas kompiuterio programoje priima didesnę atsarginę kopiją.',
        'enter_passphrase' => 'Įvesk slaptafrazę, kuria atsarginė kopija buvo užšifruota.',
        'unreadable' => 'Įkelto failo perskaityti nepavyko. Bandyk dar kartą.',
        'restore_wrong_passphrase' => 'Ši slaptafrazė šios atsarginės kopijos neatidarė ir niekas nebuvo pakeista. Įvesk ją iš naujo ir bandyk dar kartą. Jei ji tikrai teisinga, failas buvo pakeistas po sukūrimo — tada atkurk iš kitos kopijos.',
        'restore_not_a_backup' => 'Šiame faile nėra Beatrax atsarginės kopijos, todėl nėra ką atkurti ir niekas nebuvo pakeista. Pasirink .enc failą, kurį programa įrašė kuriant kopiją, arba .zip archyvą, kurį įrašė eksportas vienu paspaudimu.',
        'restore_contents_unreadable' => 'Atsarginė kopija atsidarė, bet duomenų bazė joje sugadinta, todėl ji nebuvo atkurta ir niekas nebuvo pakeista. Atkurk iš senesnės kopijos.',
        'restore_could_not_read' => 'Atsarginės kopijos failo nepavyko perskaityti, todėl atkūrimas nevyko ir niekas nebuvo pakeista. Patikrink, ar įrenginyje yra laisvos vietos, ir bandyk dar kartą.',
        'restore_not_supported' => 'Atkūrimas veikia leidime, kuris laiko duomenis viename faile, o šis toks nėra, todėl niekas nebuvo pakeista. Serverio duomenų bazėje naudok jos pačios atkūrimo įrankius.',
        'restore_failed' => 'Atkūrimas nevyko ir niekas nebuvo pakeista. Bandyk dar kartą — jei vis nepavyksta, programos žurnale užrašyta, kas jį sustabdė.',
    ],
];
