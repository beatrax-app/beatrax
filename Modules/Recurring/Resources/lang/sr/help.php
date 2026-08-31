<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Izvod je ravna lista datuma i iznosa i ništa u njemu ne kaže koji su redovi ista trajna obaveza. Beatrax grupiše redove po tome kome je plaćeno, odbacuje iznose koji odudaraju od grupe i predlaže seriju tek kad se razmaci među njima slegnu u ustaljen nedeljni, mesečni, tromesečni ili godišnji ritam — sve manje pravilno ne predlaže uopšte. Unazad čita samo dokle seže „:setting” u podešavanjima, a to počinje od najkraćeg perioda s kojim uopšte može da radi, pa godišnji račun ostaje van vidokruga dok ga ne proširiš. Tvojim podacima se ovde ništa ne dešava dok to ne odobriš.',
];
