<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Reconciliar é comparar o Beatrax com o número do próprio banco. O saldo reconciliado é o saldo inicial desta conta mais cada linha que marcaste como reconciliada até à data do extrato, e a diferença é o número do teu extrato menos esse saldo. Marca ou desmarca linhas na lista de movimentos até a diferença chegar a zero — este ecrã nunca inventa um lançamento de acerto. “:complete” bloqueia depois as linhas abrangidas: uma linha bloqueada não pode ser editada, dividida nem eliminada até a desbloqueares na página dela.',
];
