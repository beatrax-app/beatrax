<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Novac koji je već stigao, a još nema omotnicu: prihodi ovog razdoblja, plus ono što je prošlo razdoblje ostalo neraspoređeno, minus sve raspoređeno niže. Spusti ga na nulu i ništa ne ostaje bez plana. Ispod nule znači da si rasporedio više nego što je zaista stiglo — uzmi nešto natrag iz neke omotnice ili pričekaj sljedeću plaću.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Što se događa s omotnicom koja je potrošila više nego što u njoj ima, kad razdoblje završi. Uz „:reduce” manjak se odbija odmah od onoga što ćeš iduće razdoblje imati za rasporediti, a sama omotnica opet kreće od nule. Uz „:carry” manjak ostaje ondje gdje je nastao: ta omotnica otvara se ispod nule i mora se dopuniti prije nego što išta plati, a ostatak plana se ne miče.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'available' => 'Što ova omotnica još može platiti: „:assigned” za ovo razdoblje, plus „:carried”, plus ili minus „:moved”, minus „:spent” — četiri stupca lijevo. To nije stanje na računu: više omotnica crpi s istog računa, a ovaj iznos govori samo o ovoj. Ispod nule omotnica je već potrošila više nego što u njoj ima, a kraj razdoblja odlučuje što će biti s tim manjkom.',
];
