<?php

declare(strict_types=1);

return [
    'what_heading' => 'Despre ce să te anunțăm',
    'background_note' => 'Beatrax le pregătește cât timp aplicația este deschisă. O rulare programată în fundal nu poate — blocarea aplicației păstrează singura cheie — așa că cele în așteptare sunt preluate în timp ce continui să folosești aplicația.',
    'background_note_phone' => 'Beatrax le pregătește cât timp aplicația este deschisă. În fundal nu poate — blocarea aplicației păstrează singura cheie — așa că cele în așteptare ajung data viitoare când deschizi aplicația.',
    'system_grant_refused' => 'Dispozitivul tău nu îi permite lui Beatrax să afișeze notificări, așa că nimic din ce urmează nu ajunge la tine. Activează-le pentru Beatrax din setările dispozitivului.',

    'reminders' => [
        'label' => 'Memento-uri de plată',
        'help' => 'Primește un semnal înainte ca o plată recurentă să devină scadentă.',
    ],

    'lead_days' => [
        'label' => 'Amintește-mi cu ___ zile înainte',
        'help' => 'Cu câte zile înainte de scadență se declanșează mementoul. 1–30 de zile.',
    ],

    'budget_nudges' => [
        'label' => 'Avertizări de buget',
        'help' => 'Primești un semnal când bugetul unei categorii este aproape epuizat.',
    ],

    'digest' => [
        'label' => 'Situația ta',
        'help' => 'Cât de des primești un rezumat al situației din această perioadă.',
        'daily' => 'Zilnic',
        'weekly' => 'Săptămânal',
        'off' => 'Oprit',
    ],

    'savings' => [
        'label' => 'Sugestii de economisire',
        'help' => 'Primești un semnal când Beatrax găsește un plan mai ieftin sau un loc unde ai putea economisi.',
    ],

    'when_heading' => 'Când și cum',

    'quiet_hours' => [
        'label' => 'Ore de liniște',
        'help' => 'Fără sunet sau banner în acest interval — notificările ajung în continuare în inbox.',
        'from' => 'De la',
        'to' => 'Până la',
    ],

    'hide_details' => [
        'label' => 'Ascunde detaliile în notificări',
        'help' => 'Ascunde sumele și numele comercianților chiar în bannerul notificării. Pornește dacă ecranul tău poate fi văzut de alții.',
    ],

    'save' => 'Salvează setările de notificare',
    'saved' => 'Salvat.',

    'other_devices' => [
        'summary' => 'Alte dispozitive',
        'empty' => 'Niciun alt dispozitiv împerecheat deocamdată.',
        'unnamed' => 'Dispozitiv fără nume',

        'summary_line' => 'memento-uri :reminders · avertizări :nudges · rezumat :digest · economisire :savings',
        'on' => 'pornit',
        'off' => 'oprit',
    ],

    'errors' => [
        'save_failed' => 'Nu s-au putut salva setările de notificare. Încearcă din nou.',
    ],
];
