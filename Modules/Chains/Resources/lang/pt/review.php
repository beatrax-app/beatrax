<?php

declare(strict_types=1);

return [
    'page_title' => 'Rever cadeias',
    'heading' => 'Rever cadeias',
    'hint' => ':count pista|:count pistas',
    'subtitle' => 'Confirma ou rejeita as ligações candidatas que o resolvedor de cadeias não conseguiu confirmar automaticamente.',

    'empty_heading' => 'Nada para rever',
    'empty_body' => 'Todas as ligações que o resolver conseguiu emparelhar estão confirmadas ou rejeitadas. Os novos candidatos aparecem aqui à medida que chegam importações.',

    'auto_confirm_nudge' => 'Mais uma confirmação e as ligações semelhantes passam a confirmar-se automaticamente.',

    'confirm' => 'Confirmar',
    'reject' => 'Rejeitar',
    'confirm_aria' => 'Confirmar a ligação de cadeia :id',
    'reject_aria' => 'Rejeitar a ligação de cadeia :id',
    'show_more' => 'Mostrar mais',

    'kind' => [
        'paypal_funding' => 'Financiamento por PayPal',
        'ics_bulk_settle' => 'Liquidação iDEAL agrupada',
    ],

    'errors' => [
        'confirm_hint' => 'Este candidato é uma pista — abre-o para anexar a transação correspondente antes de confirmar.',
        'reject_hint' => 'Este candidato é uma pista — abre-o para anexar a transação correspondente antes de rejeitar.',
    ],
];
