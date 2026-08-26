<?php

declare(strict_types=1);

return [
    'page_title' => 'Avstämning',
    'heading' => 'Avstämning',
    'intro' => 'Bekräfta ett kontos saldo enligt kontoutdraget mot dina bokförda transaktioner. När de stämmer överens slutför du avstämningen för att låsa raderna.',

    'account' => 'Konto',
    'choose_account' => 'Välj ett konto…',
    'statement_date' => 'Kontoutdragets datum',
    'statement_balance' => 'Saldo enligt kontoutdrag (:symbol)',
    'balance_help' => 'Förifylls från ditt senast importerade kontoutdrag när det går — negativt för skuld, redigerbart i båda fallen.',

    'cleared_balance' => 'Bokfört saldo',
    'statement_target' => 'Målsaldo enligt kontoutdrag',
    'difference' => 'Skillnad',

    'pill' => [
        'choose_account' => 'välj ett konto',
        'enter_balance' => 'ange ett saldo enligt kontoutdraget',
        'matched' => 'stämmer — :amount',
        'discrepancy' => 'avvikelse — :amount',
    ],

    'mismatch_html' => 'Saldot enligt kontoutdraget stämmer ännu inte med ditt bokförda saldo. Växla bokförda rader i <a href=":url" class="underline">transaktionslistan</a> eller justera det angivna saldot tills skillnaden når noll — det här flödet skapar aldrig en utjämnande post.',

    'check' => 'Kontrollera',
    'complete' => 'Slutför avstämningen',

    'errors' => [
        'choose_account' => 'Välj ett konto först.',
        'invalid_balance_date' => 'Ange ett giltigt saldo enligt kontoutdraget och ett giltigt datum.',
        'mismatch' => 'Saldot enligt kontoutdraget stämmer ännu inte med det bokförda saldot — justera bokförda rader eller det angivna saldot tills skillnaden är noll.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Inget att låsa för det här kontoutdragsdatumet.',
        'complete' => 'Avstämningen är klar — :count rad låst.|Avstämningen är klar — :count rader låsta.',
    ],
];
