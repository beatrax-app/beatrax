<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Reconciliar é comparar o Beatrax com o número do próprio banco. O saldo reconciliado é o saldo inicial desta conta mais cada linha que marcaste como reconciliada até à data do extrato, e a diferença é o número do teu extrato menos esse saldo. Marca ou desmarca linhas na lista de movimentos até a diferença chegar a zero — este ecrã nunca inventa um lançamento de acerto. “:complete” bloqueia depois as linhas abrangidas: uma linha bloqueada não pode ser editada, dividida nem eliminada até a desbloqueares na página dela.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Em que ponto está uma linha face ao teu extrato bancário. “:uncleared” quer dizer que o Beatrax tem o movimento mas tu ainda não o confrontaste com um extrato; toca na pastilha para o pôr em “:cleared”, e são essas marcas que o ecrã de reconciliação soma. “:reconciled” não se toca: é uma reconciliação concluída que o define, e essa tranca a linha — categoria, nota, divisão e etiquetas fiscais ficam como estão até a destrancares.',
];
