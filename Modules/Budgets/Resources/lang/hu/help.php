<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Pénz, ami már megérkezett, de még nincs borítékban: ennek az időszaknak a bevétele, plusz az, ami az előző időszakból felosztatlan maradt, mínusz minden, ami lent fel van osztva. Vidd le nullára, és semmi sem marad terv nélkül. A nulla alatt többet osztottál fel, mint amennyi ténylegesen befolyt — vegyél vissza valamennyit egy borítékból, vagy várd meg a következő fizetést.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Mi történik azzal a borítékkal, amelyik többet költött, mint amennyi benne van, amint az időszak véget ér. A „:reduce” választásával a hiány elsőként abból jön le, amit a következő időszakban fel tudsz osztani, maga a boríték pedig újra nulláról indul. A „:carry” választásával a hiány ott marad, ahol keletkezett: az a boríték nulla alatt nyit, és fel kell tölteni, mielőtt bármit kifizetne, a terv többi része pedig érintetlen marad.',
];
