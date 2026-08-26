<?php

declare(strict_types=1);

return [
    'page_title' => 'Rapprochement',
    'heading' => 'Rapprochement',
    'intro' => 'Confronte le solde du relevé d\'un compte à tes transactions compensées. Quand les deux concordent, termine le rapprochement pour verrouiller ces lignes.',

    'account' => 'Compte',
    'choose_account' => 'Choisis un compte…',
    'statement_date' => 'Date du relevé',
    'statement_balance' => 'Solde du relevé (:symbol)',
    'balance_help' => 'Prérempli à partir de ton dernier relevé importé quand c\'est possible — négatif pour un montant dû, modifiable dans les deux cas.',

    'cleared_balance' => 'Solde compensé',
    'statement_target' => 'Cible du relevé',
    'difference' => 'Écart',

    'pill' => [
        'choose_account' => 'choisis un compte',
        'enter_balance' => 'saisis un solde de relevé',
        'matched' => 'concordant — :amount',
        'discrepancy' => 'écart — :amount',
    ],

    'mismatch_html' => 'Le solde du relevé ne correspond pas encore à ton solde compensé. Bascule des lignes en compensé dans la <a href=":url" class="underline">liste des transactions</a> ou ajuste le solde saisi jusqu\'à ce que l\'écart tombe à zéro — ce flux ne crée jamais d\'écriture d\'équilibrage.',

    'check' => 'Vérifier',
    'complete' => 'Terminer le rapprochement',

    'errors' => [
        'choose_account' => 'Choisis d\'abord un compte.',
        'invalid_balance_date' => 'Saisis un solde de relevé et une date valides.',
        'mismatch' => 'Le solde du relevé ne correspond pas encore au solde compensé — ajuste les lignes compensées ou le solde saisi jusqu\'à ce que l\'écart soit nul.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Rien à verrouiller pour cette date de relevé.',
        'complete' => 'Rapprochement terminé — :count ligne verrouillée.|Rapprochement terminé — :count lignes verrouillées.',
    ],
];
