<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Denar, ki je že prispel in še nima kuverte: prihodki tega obdobja, plus to, kar je v prejšnjem obdobju ostalo nerazporejeno, minus vse razporejeno spodaj. Spravi ga na nič in nič ne ostane brez načrta. Pod ničlo pomeni, da si razporedil več, kot je dejansko prišlo — vzemi nekaj nazaj iz katere od kuvert ali počakaj na naslednjo plačo.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Kaj se zgodi s kuverto, ki je porabila več, kot je v njej, ko se obdobje izteče. Pri „:reduce“ se primanjkljaj odšteje že od tega, kar boš imel naslednje obdobje za razporediti, sama kuverta pa se spet začne pri nič. Pri „:carry“ primanjkljaj ostane tam, kjer je nastal: ta kuverta se odpre pod ničlo in jo je treba dopolniti, preden spet kaj plača, preostanek načrta pa se ne premakne.',
];
