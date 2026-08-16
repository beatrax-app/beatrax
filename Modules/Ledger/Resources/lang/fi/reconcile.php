<?php

declare(strict_types=1);

return [
    'page_title' => 'Täsmäytys',
    'heading' => 'Täsmäytys',
    'intro' => 'Vahvista tilin tiliotesaldo kuitattuja tapahtumiasi vasten. Kun ne täsmäävät, viimeistele täsmäytys, niin rivit lukittuvat paikoilleen.',

    'account' => 'Tili',
    'choose_account' => 'Valitse tili…',
    'statement_date' => 'Tiliotteen päivä',
    'statement_balance' => 'Tiliotteen saldo (€)',
    'balance_help' => 'Esitäytetään uusimmasta tuodusta tiliotteesta, kun sellainen on saatavilla — negatiivinen velalle, muokattavissa kumpaankin suuntaan.',

    'cleared_balance' => 'Kuitattu saldo',
    'statement_target' => 'Tiliotteen tavoite',
    'difference' => 'Erotus',

    'pill' => [
        'choose_account' => 'valitse tili',
        'enter_balance' => 'syötä tiliotteen saldo',
        'matched' => 'täsmää — :amount',
        'discrepancy' => 'ero — :amount',
    ],

    'mismatch_html' => 'Tiliotteen saldo ei vielä vastaa kuitattua saldoasi. Vaihda rivien kuittaustilaa <a href=":url" class="underline">tapahtumalistalla</a> tai muuta syötettyä saldoa, kunnes erotus on nolla — tämä kulku ei koskaan luo tasauskirjausta.',

    'check' => 'Tarkista',
    'complete' => 'Viimeistele täsmäytys',

    'errors' => [
        'choose_account' => 'Valitse ensin tili.',
        'invalid_balance_date' => 'Anna kelvollinen tiliotteen saldo ja päivä.',
        'mismatch' => 'Tiliotteen saldo ei vielä vastaa kuitattua saldoa — muuta rivien kuittaustilaa tai syötettyä saldoa, kunnes erotus on nolla.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Ei mitään lukittavaa tälle tiliotepäivälle.',
        'complete' => 'Täsmäytys valmis — :count riviä lukittu.',
    ],
];
