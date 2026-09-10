<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Melyik számlára számít egy előrejelzett fizetés. Némelyik fizetés sosem hagyja el azt a számlát, amelyikről elhagyni látszik: egy kártyás vásárlást később egyetlen terhelés rendez a bankszámládon, egy tárcás fizetést pedig egy átutalás fedez. Bekapcsolva mindegyik arra a számlára számít, amelyik valóban fizeti, így a számla vonala azt a pénzt mutatja, ami tényleg átfolyik rajta, nem azt, ami csak elhaladt mellette.',
];
