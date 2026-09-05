<?php

declare(strict_types=1);

return [
    'too_new' => [
        // i18n-review: lv · too_new.summary — Latvian selects arm 0 for zero, so
        // the genitive plural leads and the singular follows. This notice never
        // renders at zero, so that first arm ships unread and wants a native eye
        // standing on its own. The other three summary lines follow the same order.
        'summary' => ':count izmaiņu veica jaunāka Beatrax versija|:count izmaiņu veica jaunāka Beatrax versija|:count izmaiņas veica jaunāka Beatrax versija',
        'body' => 'Tas, kas tika noraidīts, norāda uz kaut ko, kā šajā Beatrax versijā nav, tāpēc šai ierīcei nebija, kur to likt. Tas joprojām ir ierīcē, kas to veica, un nekas no taviem datiem nav dzēsts.',
        'action' => 'Atjaunini Beatrax šajā ierīcē. Pēc atjaunināšanas veiktās izmaiņas pienāk kā parasti, bet nekas jau noraidīts netiek sūtīts atkārtoti — izdari izmaiņu šeit vēlreiz, ja tā vajadzīga arī šajā ierīcē.',
    ],
    'untrusted_author' => [
        'summary' => ':count izmaiņu parakstīja ierīce, kuru šī neatpazīst|:count izmaiņu parakstīja ierīce, kuru šī neatpazīst|:count izmaiņas parakstīja ierīce, kuru šī neatpazīst',
        'body' => 'Tas, kas tika noraidīts, nāca no ierīces, kas nekad nav bijusi sapārota ar šo, vai no ierīces, kuru tu noņēmi. Šeit nekas netika ierakstīts, un nekas no tā, kas jau bija šeit, netika mainīts.',
        'action' => 'Ja tu pats noņēmi to ierīci, tieši to noņemšana arī dara, un labot nav nekā. Ja nē, apskati ierīču sarakstu šajā lapā.',
    ],
    'not_verified' => [
        'summary' => ':count izmaiņu neizturēja drošības pārbaudi šajā ierīcē|:count izmaiņa neizturēja drošības pārbaudi šajā ierīcē|:count izmaiņas neizturēja drošības pārbaudi šajā ierīcē',
        'body' => 'Paraksts neatbilda ierīcei, kas apgalvoja, ka veikusi izmaiņu, vai arī izmaiņa bija adresēta citam kontam. Šeit nekas netika ierakstīts. Starp tavām ierīcēm tam nevajadzētu notikt.',
        'action' => 'Apskati ierīču sarakstu šajā lapā un noņem visu, ko neatpazīsti. Ja katra tur redzamā ierīce ir tava un tas atkārtojas, tā ir kļūda Beatrax lietotnē, nevis kaut kas, ko tu vari novērst no šejienes.',
    ],
    'diverged' => [
        'summary' => ':count izmaiņu no citas ierīces šeit nevarēja saglabāt|:count izmaiņu no citas ierīces šeit nevarēja saglabāt|:count izmaiņas no citas ierīces šeit nevarēja saglabāt',
        'body' => 'Pienāca kaut kas, ko šī ierīce nevarēja saglabāt: ieraksts, kuram trūkst daļas no sevis, datums, kāda nav, sadalījums, kas vairs nesakrīt, ieraksts, kuram divas ierīces jau bija piešķīrušas vienu un to pašu identitāti, vai kāda šeit vēl lietota ieraksta dzēšana. Tas, kas tika noraidīts, ir tavā otrā ierīcē, nevis šajā, tāpēc abas vairs nesatur vienu un to pašu.',
        'action' => 'Salīdzini ierakstu savā otrā ierīcē ar to, ko redzi šeit, un izdari izmaiņu šeit vēlreiz — vai arī izdzēs to šeit vēlreiz, ja kaut kas, ko noņēmi citur, šeit joprojām ir. Nekas noraidīts netiek sūtīts atkārtoti pats no sevis.',
    ],
    'last_seen' => 'Jaunākais: :when',
];
