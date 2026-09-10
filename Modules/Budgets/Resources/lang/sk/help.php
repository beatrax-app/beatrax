<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Peniaze, ktoré už prišli a zatiaľ nemajú obálku: príjmy tohto obdobia, plus to, čo si minule nechal nerozdelené, mínus všetko rozdelené nižšie. Zraz to na nulu a nič nezostane bez plánu. Pod nulou si rozdelil viac, než koľko naozaj prišlo — vezmi niečo späť z niektorej obálky alebo počkaj na najbližšiu výplatu.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Čo sa stane s obálkou, ktorá minula viac, než v nej je, len čo sa obdobie skončí. Pri „:reduce“ sa schodok odpočíta hneď z toho, čo budeš mať nasledujúce obdobie na rozdelenie, a samotná obálka začína znova na nule. Pri „:carry“ zostane schodok tam, kde vznikol: obálka sa otvorí pod nulou a treba ju najprv doplniť, než z nej pôjde čokoľvek zaplatiť, a so zvyškom plánu sa nič nedeje.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'available' => 'Čo táto obálka ešte môže zaplatiť: „:assigned“ za toto obdobie, plus „:carried“, plus alebo mínus „:moved“, mínus „:spent“ — štyri stĺpce vľavo. Nie je to zostatok na účte: z jedného účtu čerpá viac obálok a toto číslo hovorí len za túto jednu. Pod nulou obálka minula viac, než v nej je, a koniec obdobia rozhodne, čo sa s tým schodkom stane.',
];
