<?php

declare(strict_types=1);

return [
    'about_body' => 'Komplektā iekļauts YAML fails, kas saista neskaidrus konta izraksta kodus ar saprotamiem tirgotāju nosaukumiem. Ieslēdzot to, Beatrax importa laikā lasa šo sarakstu; ieteikuma iesniegšana atver GitHub jūsu pārlūkā.',

    'mappings' => ':count atbilstību|:count atbilstība|:count atbilstības',
    'contributors' => ':count ieguldītāju|:count ieguldītājs|:count ieguldītāji',

    'use_shared_list' => [
        'title' => 'Izmantot kopīgoto tirgotāju sarakstu',
        'help' => 'Ļaujiet Beatrax lasīt komplektā iekļauto sarakstu, lai aizpildītu saprotamus nosaukumus tirgotājiem, kuriem vēl nav jūsu piešķirta nosaukuma.',
    ],

    'offer_to_contribute' => [
        'title' => 'Piedāvāt dalīties',
        'help' => 'Rādīt pogu „Palīdziet citiem to atpazīt” šķirošanas rindā, lai ar vienu klikšķi varētu iesniegt ieteikumu kopīgotajam sarakstam.',
        // i18n-review: lv · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Rādīt pogu „Palīdziet citiem to atpazīt” šķirošanas rindā, lai ar vienu pieskārienu varētu iesniegt ieteikumu kopīgotajam sarakstam.',
    ],

    // i18n-review: lv · update_on_updates.note — "sānjosla" is the form used
    // here; Latvian interfaces also write "sānu josla", and this app names the
    // element in no other line, so a reader has no house term to match it to.
    'update_on_updates' => [
        'title' => 'Atjaunināt kopīgoto sarakstu līdz ar lietotnes atjauninājumiem',
        'help' => 'Atsvaidzināt komplektā iekļauto sarakstu ikreiz, kad Beatrax atjaunina sevi.',
        'help_phone' => 'Atsvaidzināt komplektā iekļauto sarakstu ikreiz, kad no App Store vai Google Play tiek instalēta jauna Beatrax versija.',
        'note' => 'Stāsies spēkā ar nākamo lietotnes atjauninājumu — izmantotā versija ir redzama sānjoslas augšdaļā.',
    ],
];
