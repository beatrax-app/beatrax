<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Izvadak je ravan popis datuma i iznosa i ništa u njemu ne kaže koji su retci ista trajna obveza. Beatrax grupira retke po tome kome je plaćeno, odbacuje iznose koji odudaraju od skupine i predlaže seriju tek kad se razmaci među njima slegnu u ustaljen tjedni, mjesečni, tromjesečni ili godišnji ritam — sve manje pravilno ne predlaže uopće. Unatrag čita samo dokle seže „:setting” u postavkama, a to počinje od najkraćeg razdoblja s kojim uopće može raditi, pa godišnji račun ostaje izvan vidokruga dok ga ne proširiš. Tvojim se podacima ovdje ništa ne događa dok to ne odobriš.',
];
