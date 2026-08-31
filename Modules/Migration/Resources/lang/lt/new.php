<?php

declare(strict_types=1);

return [
    'page_title' => 'Importas iš YNAB / Actual',

    'eyebrow' => 'Duomenų perkėlimai',
    'heading' => 'Importas iš YNAB / Actual',
    'intro' => 'Perkelk kategorijų medį, biudžeto istoriją ir operacijas iš YNAB4, naujojo YNAB arba Actual Budget. Kol neperžiūrėsi ir nepatvirtinsi, į didžiąją knygą nieko neįrašoma.',
    'reconcile_context' => 'Tikrinama, ar yra atnaujinimų nuo paskutinio :product importo.',

    'source_label' => 'Šaltinis',
    'file_label' => 'Failas',
    'parse_button' => 'Nuskaityti eksportą',

    'hints' => [
        'ynab4' => 'Eksportuok visą biudžetą kaip ZIP failą iš YNAB4 meniu File → Export.',
        'nynab' => 'Eksportuok biudžetą iš nYNAB per File → Export Budget, tada suarchyvuok eksportuotus CSV failus į ZIP.',
        'actual' => 'Eksportuok biudžetą kaip ZIP failą iš Actual Budget nustatymų Settings → Export data.',
    ],

    'errors' => [
        'unrecognised' => 'Tai nepanašu į YNAB4, nYNAB ar Actual eksportą, kurį galėtume perskaityti. Patikrink failą ir bandyk dar kartą.',
        'file_too_large' => 'Šis failas per didelis perkėlimo eksportui.',
        'archive_reader_unavailable' => 'Ši programos versija neturi ZIP skaitytuvo, kuris atvertų šį eksportą, tad čia jo perskaityti nepavyks. Importuok jį kompiuterio programoje arba supakuok eksportą iš naujo įprastu glaudinimu.',
        'internal_detail' => 'Programai nepavyko perskaityti šio eksporto (:code). Visa informacija yra programos žurnale; pranešdamas apie problemą nurodyk šį kodą.',
    ],
];
