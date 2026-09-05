<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Šī lietotne nevar nodot failu tavai ierīcei, tāpēc šifrētais dublējums tiek veidots datora lietotnē. Savieno šo ierīci, lai abas paliktu sinhronizētas.',
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
        'file_label' => 'Dublējuma fails (.enc) vai eksporta arhīvs (.zip)',
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
        'choose_file' => 'Izvēlieties, no kā atjaunot: .enc dublējuma failu vai .zip arhīvu, ko izveidoja eksports ar vienu klikšķi.',
        'upload_failed' => 'Fails netika augšupielādēts līdz galam. Iespējams, tas ir pārāk liels šai ierīcei — atjaunošana datora lietotnē pieņem lielāku dublējumu.',
        'enter_passphrase' => 'Ievadiet paroles frāzi, ar kuru dublējums tika šifrēts.',
        'unreadable' => 'Augšupielādēto failu neizdevās nolasīt. Mēģiniet vēlreiz.',
        'restore_wrong_passphrase' => 'Šī paroles frāze šo dublējumu neatvēra, un nekas netika mainīts. Ievadiet to vēlreiz un mēģiniet vēlreiz. Ja tā noteikti ir pareiza, fails pēc izveides ir mainīts — tad atjaunojiet no citas kopijas.',
        'restore_not_a_backup' => 'Šajā failā nav Beatrax dublējuma, tāpēc nav ko atjaunot un nekas netika mainīts. Izvēlieties .enc failu, ko lietotne ierakstīja dublējuma izveides laikā, vai .zip arhīvu, ko izveidoja eksports ar vienu klikšķi.',
        'restore_contents_unreadable' => 'Dublējums atvērās, bet tajā esošā datubāze ir bojāta, tāpēc tā netika atjaunota un nekas netika mainīts. Atjaunojiet no vecāka dublējuma.',
        'restore_could_not_read' => 'Dublējuma failu neizdevās nolasīt, tāpēc atjaunošana nenotika un nekas netika mainīts. Pārbaudiet, vai ierīcē ir brīva vieta, un mēģiniet vēlreiz.',
        'restore_not_supported' => 'Atjaunošana darbojas laidienā, kas glabā datus vienā failā, un šis tāds nav, tāpēc nekas netika mainīts. Servera datubāzei izmantojiet tās pašas atjaunošanas rīkus.',
        'restore_failed' => 'Atjaunošana nenotika, un nekas netika mainīts. Mēģiniet vēlreiz — ja tas joprojām neizdodas, lietotnes žurnālā ir pierakstīts, kas to apturēja.',
    ],
];
