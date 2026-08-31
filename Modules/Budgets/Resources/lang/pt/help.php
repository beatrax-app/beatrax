<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Dinheiro que já entrou e ainda não tem envelope: os rendimentos deste período, mais o que ficou por atribuir no período anterior, menos tudo o que está atribuído abaixo. Leva-o a zero e não fica nada por planear. Abaixo de zero atribuíste mais do que aquilo que realmente entrou — retira algo de um envelope ou espera pelo próximo ordenado.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'O que acontece a um envelope que gastou mais do que tem, assim que o período termina. Com “:reduce”, a diferença é descontada logo àquilo que tens para distribuir no período seguinte e o envelope volta a começar em zero. Com “:carry”, a diferença fica onde surgiu: esse envelope abre abaixo de zero e tem de ser reposto antes de voltar a pagar seja o que for, e o resto do plano não é mexido.',
];
