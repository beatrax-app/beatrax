<?php

declare(strict_types=1);

return [
    'page_title' => 'Täsmäytys',
    'heading' => 'Täsmäytys',
    'intro' => 'Vahvista tilin tiliotesaldo kuitattuja tapahtumiasi vasten. Kun ne täsmäävät, viimeistele täsmäytys, niin rivit lukittuvat paikoilleen.',

    'account' => 'Tili',
    'choose_account' => 'Valitse tili…',
    'statement_date' => 'Tiliotteen päivä',
    'statement_balance' => 'Tiliotteen saldo (:symbol)',
    'balance_help' => 'Esitäytetään uusimmasta tuodusta tiliotteesta, kun sellainen on saatavilla — negatiivinen velalle, muokattavissa kumpaankin suuntaan.',

    'cleared_balance' => 'Kuitattu saldo',
    'statement_target' => 'Tiliotteen tavoite',
    'difference' => 'Erotus',

    'pill' => [
        'choose_account' => 'valitse tili',
        'choose_date' => 'valitse tiliotteen päivä',
        'enter_balance' => 'syötä tiliotteen saldo',
        'matched' => 'täsmää — :amount',
        'discrepancy' => 'ero — :amount',
        'reconciled_through' => 'täsmäytetty :date saakka',
    ],

    'mismatch_html' => 'Tiliotteen saldo ei vielä vastaa kuitattua saldoasi. Vaihda rivien kuittaustilaa <a href=":url" class="underline">tapahtumalistalla</a> tai muuta syötettyä saldoa, kunnes erotus on nolla — tämä kulku ei koskaan luo tasauskirjausta.',
    'unreachable_no_baseline_html' => 'Rivien vaihtelu ei saa tätä eroa nollaan. Tälle tilille ei ole kirjattu alkusaldoa, joten sen saldo mitataan nollasta. Tuo tiliote, jolla tili avautuu, tai aseta alkusaldo <a href=":url" class="underline">Asetuksissa</a>.',
    'unreachable' => 'Rivien vaihtelu ei saa tätä eroa nollaan: se on kaikkien tämän tilin rivien vaihteluvälin ulkopuolella annettuun päivään asti. Tarkista tiliotteen päivä ja syöttämäsi saldo.',

    'check' => 'Tarkista',
    'complete' => 'Viimeistele täsmäytys',
    'complete_unavailable' => 'Tähän päivään asti ei ole enää mitään lukittavaa — kuittaa lisää rivejä tai valitse myöhempi tiliotteen päivä.',

    'errors' => [
        'choose_account' => 'Valitse ensin tili.',
        'invalid_balance_date' => 'Anna kelvollinen tiliotteen saldo ja päivä.',
        'mismatch' => 'Tiliotteen saldo ei vielä vastaa kuitattua saldoa — muuta rivien kuittaustilaa tai syötettyä saldoa, kunnes erotus on nolla.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Ei mitään lukittavaa tälle tiliotepäivälle.',
        'complete' => 'Täsmäytys valmis — :count rivi lukittu.|Täsmäytys valmis — :count riviä lukittu.',
    ],
];
