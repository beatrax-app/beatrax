<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Taupyklė atideda pinigus sąskaitos viduje, o ne išima juos iš jos, tad banko likutis nesikeičia, kai ją papildai arba iš jos išimi. Prie kiekvienos sąskaitos yra trys skaičiai: „:real“ yra tai, ką laiko bankas, „:allocated“ yra tai, ką tavo taupyklės kartu pasiėmė, o „:unallocated“ yra likutis — vienintelė dalis, kurią dar galima laisvai išleisti. Kai paskutinis skaičius nukrenta žemiau nulio, taupyklės pretenduoja į daugiau, nei sąskaitoje yra, ir kiekvienos likutis yra per didelis, kol ko nors neatsiimi.',
];
