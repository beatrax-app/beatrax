<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Novac koji je već stigao, a još nema koverat: prihodi ovog perioda, plus ono što je prošlog perioda ostalo neraspoređeno, minus sve raspoređeno niže. Spusti ga na nulu i ništa ne ostaje bez plana. Ispod nule znači da si rasporedio više nego što je zaista stiglo — uzmi nešto nazad iz nekog koverta ili sačekaj sledeću platu.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Šta se dešava s kovertom koji je potrošio više nego što u njemu ima, kad se period završi. Uz „:reduce” manjak se oduzima odmah od onoga što ćeš sledećeg perioda imati za raspoređivanje, a sam koverat opet kreće od nule. Uz „:carry” manjak ostaje tamo gde je nastao: taj koverat se otvara ispod nule i mora da se dopuni pre nego što išta plati, a ostatak plana se ne pomera.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'available' => 'Šta ovaj koverat još može da plati: „:assigned” za ovaj period, plus „:carried”, plus ili minus „:moved”, minus „:spent” — četiri kolone levo. To nije stanje na računu: sa istog računa crpi više koverata, a ovaj iznos govori samo o ovom. Ispod nule koverat je već potrošio više nego što u njemu ima, a kraj perioda odlučuje šta će biti s tim manjkom.',
];
