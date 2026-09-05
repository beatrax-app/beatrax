<?php

declare(strict_types=1);

return [
    'about_body' => 'Kartu pateikiamas YAML failas, siejantis neaiškius sąskaitos išrašo kodus su aiškiais prekybininkų pavadinimais. Įjungus Beatrax gali skaityti šį sąrašą importuodama; pateikus pasiūlymą naršyklėje atsidaro GitHub.',

    'mappings' => ':count susiejimas|:count susiejimai|:count susiejimų',
    // i18n-review: lt · contributors — the file had the definite prisidėjusieji,
    // which a numeral cannot govern, so these are the indefinite participle forms.
    // Whether a noun such as talkininkai reads better beside a count is open.
    'contributors' => ':count prisidėjęs|:count prisidėję|:count prisidėjusių',

    'use_shared_list' => [
        'title' => 'Naudoti bendrą prekybininkų sąrašą',
        'help' => 'Leisti Beatrax skaityti kartu pateiktą sąrašą ir užpildyti aiškius pavadinimus prekybininkams, kurių pats nepervadinai.',
    ],

    'offer_to_contribute' => [
        'title' => 'Siūlyti prisidėti',
        'help' => 'Rodyti raginimą „Padėk kitiems tai atpažinti“ rūšiavimo eilutėje, kad vienu spustelėjimu galėtum pateikti pasiūlymą bendram sąrašui.',
        // i18n-review: lt · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Rodyti raginimą „Padėk kitiems tai atpažinti“ rūšiavimo eilutėje, kad vienu palietimu galėtum pateikti pasiūlymą bendram sąrašui.',
    ],

    // i18n-review: lt · update_on_updates.note — "šoninė juosta" is the form
    // used here; Lithuanian interfaces also say "šoninis skydelis", and this app
    // names the element in no other line, so a reader has no house term for it.
    'update_on_updates' => [
        'title' => 'Atnaujinti bendrą sąrašą kartu su programėlės atnaujinimais',
        'help' => 'Atnaujinti kartu pateiktą sąrašą kaskart, kai Beatrax atsinaujina.',
        'help_phone' => 'Atnaujinti kartu pateiktą sąrašą kaskart, kai iš „App Store“ arba „Google Play“ įdiegiama nauja Beatrax versija.',
        'note' => 'Pradės veikti su būsimu programėlės atnaujinimu — naudojama versija rodoma šoninės juostos viršuje.',
    ],
];
