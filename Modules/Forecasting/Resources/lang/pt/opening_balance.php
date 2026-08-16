<?php

declare(strict_types=1);

return [
    'help_paypal' => 'As exportações do PayPal não incluem linhas de saldo, por isso define este saldo manualmente.',
    'help_asn' => 'Ancorado automaticamente a partir do teu extrato mais recente. Só o substituas se souberes que o saldo real é diferente.',
    'help_default' => 'Só o substituas se souberes que o saldo real atual é diferente do que o Beatrax calcula.',

    'legend' => 'Saldo inicial da previsão para :name',
    'opening_label' => 'Saldo inicial',
    'opening_placeholder' => 'ex.: 1.250,00',
    'as_of_label' => 'Saldo inicial à data de',
    'as_of_help' => 'A data em que o valor acima é válido.',

    'divergence' => 'Isto difere em mais de €500 do saldo que o Beatrax calcula a partir das transações importadas. Tens a certeza?',
    'use_beatrax' => 'Usar o número do Beatrax',
    'use_mine' => 'Usar o meu número',

    'save' => 'Guardar o saldo inicial',
    'saved' => 'Guardado.',

    'toast' => [
        'updated' => 'Saldo inicial atualizado.',
    ],

    'errors' => [
        'invalid_number' => 'O saldo inicial tem de ser um número válido.',
        'date_required' => 'Escolhe a data a que este saldo inicial se aplica.',
        'date_invalid' => 'A data do saldo inicial tem de ser uma data ISO válida (YYYY-MM-DD).',
        'date_future' => 'A data do saldo inicial não pode estar no futuro.',
    ],
];
