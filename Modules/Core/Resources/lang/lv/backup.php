<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Šis tālrunis nevar saglabāt failu, ko lietotne tam nodod, tāpēc šifrētā dublējuma kopija tiek veidota datora lietotnē. Savieno šo ierīci, lai abas paliktu sinhronizētas.',
        'unavailable' => 'Šifrēti dublējumi ir pieejami darbvirsmas (SQLite) versijā. Servera datubāzē izmantojiet pašas datubāzes dublēšanas rīkus.',
        'intro' => 'Lejupielādējiet ar paroles frāzi šifrētu visas datubāzes kopiju — to var droši glabāt ārējā diskā vai mākonī, jo bez paroles frāzes tā nav nolasāma (kvantu drošs XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Paroles frāze',
        'confirm_passphrase' => 'Apstipriniet paroles frāzi',
        'keep_safe' => 'Glabājiet paroles frāzi drošībā — bez tās dublējumu atgūt nav iespējams.',
        'submit' => 'Lejupielādēt šifrētu dublējumu',
        'preparing' => 'Sagatavo…',
    ],

    'restore' => [
        'heading' => 'Atjaunot no dublējuma',

        'intro_html' => 'Aizstājiet pašreizējo datubāzi ar šifrētu dublējumu. Fails tiek atšifrēts un pārbaudīts, pirms kaut kas mainās, un vispirms tiek saglabāts pašreizējo datu momentuzņēmums — tomēr tas joprojām <strong class="text-slate-700 dark:text-slate-200">pārraksta visu</strong>, tāpēc darbība ir ierobežota. Tu tiksi izrakstīts, jo arī tava pieteikšanās ir datubāzē.',
        'restored' => 'Dublējums tika atjaunots. Piesakieties ar lietotājvārdu un paroli, kas bija spēkā tā izveides brīdī.',
        'snapshot_saved_prefix' => 'Jūsu iepriekšējo datu momentuzņēmums tika saglabāts šeit:',
        'file_label' => 'Šifrēts dublējums (.enc)',
        'uploading' => 'Augšupielādē…',
        'passphrase' => 'Paroles frāze',
        'confirm_prefix' => 'Ievadiet',
        'confirm_suffix' => 'lai apstiprinātu',
        'submit' => 'Atjaunot (pārraksta pašreizējos datus)',
        'restoring' => 'Atjauno…',
    ],

    'errors' => [
        'passphrase_min' => 'Izmantojiet paroles frāzi ar vismaz :min rakstzīmēm.|Izmantojiet paroles frāzi ar vismaz :min rakstzīmi.|Izmantojiet paroles frāzi ar vismaz :min rakstzīmēm.',
        'passphrase_mismatch' => 'Abas paroles frāzes nesakrīt.',
        'download_sqlite_only' => 'Šifrēta lejupielāde ir pieejama tikai SQLite versijā.',
        'create_failed' => 'Neizdevās izveidot dublējumu: :message',
        'confirm_phrase' => 'Ievadiet :phrase, lai apstiprinātu — tas aizstās jūsu pašreizējos datus.',
        'choose_file' => 'Izvēlieties šifrētu dublējuma failu (.enc), ko atjaunot.',
        'upload_failed' => 'Fails netika augšupielādēts līdz galam. Iespējams, tas ir pārāk liels šai ierīcei — atjaunošana datora lietotnē pieņem lielāku dublējumu.',
        'enter_passphrase' => 'Ievadiet paroles frāzi, ar kuru dublējums tika šifrēts.',
        'unreadable' => 'Augšupielādēto failu neizdevās nolasīt. Mēģiniet vēlreiz.',
    ],
];
