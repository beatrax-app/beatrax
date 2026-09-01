<?php

declare(strict_types=1);

return [
    'about_body' => 'Kaasas olev YAML-fail, mis seob krüptilised kontoväljavõtte koodid arusaadavate kaupmeeste nimedega. Sisselülitamine lubab Beatraxil nimekirja importimisel lugeda; ettepaneku saatmine avab brauseris GitHubi.',

    'mappings' => ':count seos|:count seost',
    'contributors' => ':count panustaja|:count panustajat',

    'use_shared_list' => [
        'title' => 'Kasuta jagatud kaupmeeste nimekirja',
        'help' => 'Luba Beatraxil lugeda kaasas olevat nimekirja, et täita arusaadavad nimed kaupmeestele, keda sa pole ise ümber nimetanud.',
    ],

    'offer_to_contribute' => [
        'title' => 'Paku panustamist',
        'help' => 'Näita sortimisreal nuppu „Aita teistel see tuvastada“, et saaksid ühe klõpsuga jagatud nimekirja ettepaneku saata.',
        // i18n-review: et · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Näita sortimisreal nuppu „Aita teistel see tuvastada“, et saaksid ühe puudutusega jagatud nimekirja ettepaneku saata.',
    ],

    'update_on_updates' => [
        'title' => 'Uuenda jagatud nimekirja koos rakendusega',
        'help' => 'Värskenda kaasas olevat nimekirja iga kord, kui Beatrax end uuendab.',
        'help_phone' => 'Värskenda kaasas olevat nimekirja iga kord, kui App Store’ist või Google Playst paigaldatakse Beatraxi uus versioon.',
        'note' => 'Aktiveerub tulevase rakenduse uuendusega — praegust versiooni vaata jaotisest Seaded → Teave.',
    ],
];
