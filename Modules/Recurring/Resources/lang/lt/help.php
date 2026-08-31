<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Išrašas yra plokščias datų ir sumų sąrašas, ir niekas jame nesako, kurios eilutės yra tas pats nuolatinis įsipareigojimas. Beatrax grupuoja eilutes pagal tai, kam sumokėta, atmeta sumas, kurios iškrenta iš grupės, ir siūlo seriją tik tada, kai tarpai tarp jų nusistovi pastoviu savaitiniu, mėnesiniu, ketvirtiniu ar metiniu ritmu — visa, kas nereguliaresnė, nesiūloma iš viso. Atgal ji skaito tik tiek, kiek leidžia „:setting“ nustatymuose, o tai prasideda nuo trumpiausio tarpsnio, su kuriuo apskritai gali dirbti, tad metinė sąskaita lieka nematoma, kol jo neišplėsi. Tavo duomenims čia niekas netaikoma, kol nepatvirtini.',
];
