<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Um extrato é uma lista plana de datas e valores, e nada nele diz que linhas são o mesmo compromisso recorrente. O Beatrax agrupa as linhas por quem foi pago, descarta os valores que destoam do grupo e só sugere uma série quando os intervalos entre elas assentam num ritmo estável semanal, mensal, trimestral ou anual — tudo o que for menos regular nunca é sugerido. Só recua até onde chega “:setting” nas Definições, que começa no intervalo mais curto com que consegue trabalhar, por isso uma conta anual fica fora de vista até o alargares. Nada é aplicado aos teus dados enquanto não aprovares.',
];
