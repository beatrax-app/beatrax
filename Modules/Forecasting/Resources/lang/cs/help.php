<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Ke kterému účtu se předpokládaná platba počítá. Některé platby nikdy neodejdou z účtu, ze kterého se zdá, že odcházejí: nákup kartou se později vyrovná jednou platbou z tvého bankovního účtu a platba z peněženky se hradí převodem do ní. Když tohle zapneš, každá z nich se počítá k účtu, který ji doopravdy platí, takže křivka účtu ukazuje peníze, které jím skutečně projdou, a ne ty, které jen prošly kolem.',
];
