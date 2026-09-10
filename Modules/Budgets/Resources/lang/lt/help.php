<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Pinigai, kurie jau atkeliavo ir dar neturi voko: šio laikotarpio pajamos, pridėjus tai, kas praėjusį laikotarpį liko nepaskirstyta, atėmus viską, kas paskirstyta žemiau. Nuvesk iki nulio ir niekas neliks nesuplanuota. Žemiau nulio reiškia, kad paskirstei daugiau, nei iš tikrųjų gavai — atsiimk dalį iš kurio nors voko arba palauk kito atlyginimo.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Kas nutinka vokui, kuris išleido daugiau, nei jame yra, kai laikotarpis baigiasi. Pasirinkus „:reduce“, trūkumas pirmiausia nuskaičiuojamas nuo to, ką turėsi paskirstyti kitą laikotarpį, o pats vokas vėl pradeda nuo nulio. Pasirinkus „:carry“, trūkumas lieka ten, kur atsirado: tas vokas atsidaro žemiau nulio ir turi būti papildytas, kol vėl už ką nors sumokės, o likusi plano dalis nejuda.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'available' => 'Ką šis vokas dar gali apmokėti: „:assigned“ šį laikotarpį, pridėjus „:carried“, pridėjus arba atėmus „:moved“, atėmus „:spent“ — keturi stulpeliai kairėje. Tai nėra sąskaitos likutis: iš tos pačios sąskaitos semiasi keli vokai, o šis skaičius kalba tik apie šį vieną. Žemiau nulio vokas jau išleido daugiau, nei jame yra, o laikotarpio pabaiga nulems, kas nutiks tam trūkumui.',
];
