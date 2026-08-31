<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Peníze, které už dorazily a zatím nemají obálku: příjmy tohoto období, plus to, co jsi minule nechal nerozděleno, minus vše rozdělené níže. Sraz to na nulu a nic nezůstane bez plánu. Pod nulou jsi rozdělil víc, než kolik skutečně přišlo — vezmi něco zpět z některé obálky nebo počkej na další výplatu.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Co se stane s obálkou, která utratila víc, než v ní je, jakmile období skončí. Při „:reduce“ se schodek odečte hned z toho, co budeš mít příští období k rozdělení, a samotná obálka začíná znovu na nule. Při „:carry“ zůstane schodek tam, kde vznikl: obálka se otevře pod nulou a musí se nejdřív doplnit, než z ní půjde cokoli zaplatit, a se zbytkem plánu se nic nestane.',
];
