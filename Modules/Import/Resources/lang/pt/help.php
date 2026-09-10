<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/import/architecture.md#applying-enrichments */
    'preview_status' => 'O que a confirmação vai fazer a cada linha. “:new” é acrescentada ao teu registo; “:duplicate” já lá está de uma importação anterior e é ignorada, por isso reimportar um extrato que se sobrepõe a outro não custa nada; “:enriched” corresponde a uma linha que já tens e preenche um detalhe que o primeiro ficheiro não trazia, sem acrescentar uma segunda. Nada neste ecrã tocou ainda no teu registo — “:confirm” é o momento em que isso acontece.',
];
