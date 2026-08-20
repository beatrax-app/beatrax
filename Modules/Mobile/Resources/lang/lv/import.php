<?php

declare(strict_types=1);

return [
    'page_title' => 'Imports no citas ierīces',

    'heading' => 'Imports no citas ierīces',
    'subtitle' => 'Iestatiet šim tālrunim savu kontu un bloķēšanu, tad sapārojiet to ar otru ierīci, lai pārņemtu vēsturi.',

    'username' => 'Lietotājvārds',
    'password' => 'Parole',
    'password_help' => 'Vismaz 12 rakstzīmes — paroles atiestatīšanas nav, ir tikai atkopšanas kodi.',
    'confirm_password' => 'Apstipriniet paroli',
    'pin' => 'Lietotnes bloķēšanas PIN kods',
    'pin_help' => '6-10 cipari — atbloķē šo ierīci.',
    'confirm_pin' => 'Apstipriniet PIN kodu',
    'continue' => 'Turpināt',

    'failed_heading' => 'Iestatīšana netika pabeigta',
    'failed_body' => 'Konts tika izveidots, taču šīs ierīces iestatīšanu nevarēja pabeigt. Varat droši mēģināt vēlreiz.',
    'try_again' => 'Mēģināt vēlreiz',

    'recovery_heading' => 'Saglabājiet šos atkopšanas kodus',
    'recovery_body' => 'Izdrukājiet tos vai saglabājiet drošā vietā. Tie vairs netiks rādīti.',
    'already_heading' => 'Šī ierīce jau ir iestatīta',
    'already_body' => 'Konts šajā ierīcē jau pastāv. Turpiniet uz sapārošanu, lai to sasaistītu ar pārējām ierīcēm.',
    'recovery_download' => 'Lejupielādēt kā .txt',
    'recovery_copy' => 'Kopēt kodus',
    'recovery_copied' => 'Nokopēts',
    'recovery_copy_failed' => 'Neizdevās nokopēt. Pierakstiet kodus.',
    'recovery_saved' => 'Saglabāts lejupielāžu mapē.',
    'recovery_share_title' => 'Beatrax atkopšanas kodi',
    'recovery_share_message' => 'Glabājiet tos drošā vietā.',
    'recovery_save_failed' => 'Failu neizdevās saglabāt. Pierakstiet kodus.',
    'recovery_confirm' => 'Šie kodi ir saglabāti drošā vietā.',
    'continue_to_pairing' => 'Turpināt uz sapārošanu',

    'errors' => [
        'passwords_mismatch' => 'Paroles nesakrīt.',
        'password_length' => 'Izmantojiet vismaz 12 rakstzīmes.',
        'pin_length' => 'PIN kodā jābūt vismaz 6 cipariem.',
        'pins_mismatch' => 'PIN kodi nesakrīt. Mēģiniet vēlreiz.',
        'session_expired' => 'Sesijas laiks beidzās, pirms iestatīšana tika pabeigta. Ievadiet PIN kodu un paroli vēlreiz.',
        'retry_failed' => 'Šīs ierīces iestatīšanu joprojām neizdevās pabeigt. Mēģiniet vēlreiz.',
        'account_failed' => 'Neizdevās izveidot kontu.',
    ],
];
