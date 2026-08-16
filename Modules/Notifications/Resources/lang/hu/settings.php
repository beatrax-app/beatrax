<?php

declare(strict_types=1);

return [
    'what_heading' => 'Miről értesítsünk',

    'reminders' => [
        'label' => 'Fizetési emlékeztetők',
        'help' => 'Kapj jelzést, mielőtt egy ismétlődő fizetés esedékessé válik.',
    ],

    'lead_days' => [
        'label' => 'Emlékeztess ___ nappal korábban',
        'help' => 'Hány nappal az esedékesség előtt szóljon az emlékeztető. 1–30 nap.',
    ],

    'budget_nudges' => [
        'label' => 'Költségvetési jelzések',
        'help' => 'Kapj szólást, ha egy kategória kerete majdnem elfogyott.',
    ],

    'digest' => [
        'label' => 'A heti helyzeted',
        'help' => 'Milyen gyakran kapj összefoglalót arról, hol tartasz ebben az időszakban.',
        'daily' => 'Naponta',
        'weekly' => 'Hetente',
        'off' => 'Ki',
    ],

    'savings' => [
        'label' => 'Megtakarítási javaslatok',
        'help' => 'Kapj szólást, ha a Beatrax olcsóbb csomagot vagy megtakarítási lehetőséget talál.',
    ],

    'when_heading' => 'Mikor és hogyan',

    'quiet_hours' => [
        'label' => 'Csendes órák',
        'help' => 'Ebben az idősávban nincs hang és felugró sáv — az értesítések ettől még megérkeznek a postaládádba.',
        'from' => 'Ettől',
        'to' => 'Eddig',
    ],

    'hide_details' => [
        'label' => 'Részletek elrejtése az értesítésekben',
        'help' => 'Megjeleníti az összegeket és a kereskedők nevét magában az értesítési sávban. Kapcsold ki, ha mások is láthatják a képernyőd.',
    ],

    'save' => 'Értesítési beállítások mentése',
    'saved' => 'Mentve.',

    'other_devices' => [
        'summary' => 'Más eszközök',
        'empty' => 'Még nincs másik párosított eszköz.',
        'unnamed' => 'Névtelen eszköz',

        'summary_line' => 'emlékeztetők :reminders · jelzések :nudges · összefoglaló :digest · megtakarítás :savings',
        'on' => 'be',
        'off' => 'ki',
    ],

    'errors' => [
        'save_failed' => 'Nem sikerült menteni az értesítési beállításaidat. Próbáld újra.',
    ],
];
