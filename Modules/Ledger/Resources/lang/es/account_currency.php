<?php

declare(strict_types=1);

return [
    'heading' => 'Moneda de la cuenta',
    'intro' => 'La moneda en la que está denominada cada cuenta. Una cuenta nueva empieza en la moneda base.',
    'no_accounts' => 'Aún no hay cuentas.',
    'legend' => 'Moneda de :name',
    'label' => 'Moneda',
    'help' => 'La moneda en la que esta cuenta presenta su saldo.',
    'save' => 'Guardar moneda',
    'saved' => 'Guardado',

    'toast' => [
        'updated' => ':name ahora presenta sus importes en :currency.',
    ],

    'errors' => [
        'unknown' => 'Esa no es una moneda que esta instalación conozca.',
    ],

    'warning' => [
        'intro' => 'Cambiar esta cuenta de :from a :to solo la reetiqueta. No se convierte ni se reescribe nada de lo almacenado.',
        'baseline' => 'Su saldo inicial de :amount se mantiene en esa cifra exacta y a partir de ahora se lee como :to.',
        'lines' => 'Esta cuenta contiene ahora:',
        'reads' => 'Tras el cambio, esta cuenta presenta su línea :to — cero si no tiene nada en :to.',
        'confirm' => 'Cambiar de todos modos',
        'keep' => 'Mantener :currency',
    ],
];
