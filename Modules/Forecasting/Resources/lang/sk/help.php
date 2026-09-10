<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Ku ktorému účtu sa počíta predpokladaná platba. Niektoré platby nikdy neodídu z účtu, z ktorého sa zdá, že odchádzajú: nákup kartou sa neskôr vyrovná jednou platbou z tvojho bankového účtu a platba z peňaženky sa kryje prevodom. Keď toto zapneš, každá z nich sa počíta k účtu, ktorý ju naozaj platí, takže krivka účtu ukazuje peniaze, ktoré ním naozaj prejdú, a nie tie, ktoré len prešli okolo.',
];
