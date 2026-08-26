<?php

declare(strict_types=1);

return [
    'heading' => 'Moeda da conta',
    'intro' => 'A moeda em que cada conta está denominada. Uma conta nova começa na moeda base.',
    'no_accounts' => 'Ainda não há contas.',
    'legend' => 'Moeda de :name',
    'label' => 'Moeda',
    'help' => 'A moeda em que esta conta apresenta o seu saldo.',
    'save' => 'Guardar moeda',
    'saved' => 'Guardado',

    'toast' => [
        'updated' => ':name passa a apresentar valores em :currency.',
    ],

    'errors' => [
        'unknown' => 'Essa não é uma moeda que esta instalação conheça.',
    ],

    'warning' => [
        'intro' => 'Mudar esta conta de :from para :to apenas a reetiqueta. Nada do que está guardado é convertido ou reescrito.',
        'baseline' => 'O saldo inicial de :amount mantém-se exatamente nesse valor e passa a ser lido como :to.',
        'lines' => 'Esta conta contém atualmente:',
        'reads' => 'Depois da mudança, esta conta apresenta a sua linha :to — zero se não detiver nada em :to.',
        'confirm' => 'Mudar mesmo assim',
        'keep' => 'Manter :currency',
    ],
];
