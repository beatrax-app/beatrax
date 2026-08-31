<?php

declare(strict_types=1);

return [
    'page_title' => 'Afstemmen',
    'heading' => 'Afstemmen',
    'intro' => 'Vergelijk het afschriftsaldo van een rekening met je verrekende transacties. Als ze overeenkomen, voltooi je de afstemming om die regels vast te zetten.',

    'account' => 'Rekening',
    'choose_account' => 'Kies een rekening…',
    'statement_date' => 'Afschriftdatum',
    'statement_balance' => 'Afschriftsaldo (:symbol)',
    'balance_help' => 'Waar mogelijk vooraf ingevuld vanuit je laatst geïmporteerde afschrift — negatief voor verschuldigd geld, in beide gevallen bewerkbaar.',

    'cleared_balance' => 'Verrekend saldo',
    'statement_target' => 'Afschriftdoel',
    'difference' => 'Verschil',

    'pill' => [
        'choose_account' => 'kies een rekening',
        'choose_date' => 'kies een afschriftdatum',
        'enter_balance' => 'voer een afschriftsaldo in',
        'matched' => 'komt overeen — :amount',
        'discrepancy' => 'verschil — :amount',
        'reconciled_through' => 'afgestemd tot en met :date',
    ],

    'mismatch_html' => 'Het afschriftsaldo komt nog niet overeen met je verrekende saldo. Wissel verrekende regels op de <a href=":url" class="underline">transactielijst</a> of pas het ingevoerde saldo aan totdat het verschil nul is — deze flow maakt nooit een correctieboeking aan.',
    'unreachable_no_baseline_html' => 'Geen enkele combinatie van regels kan dit verschil op nul brengen. Deze rekening heeft geen beginsaldo vastgelegd, dus het saldo wordt vanaf nul gemeten. Importeer het afschrift waarmee de rekening opent, of stel het beginsaldo in bij <a href=":url" class="underline">Instellingen</a>.',
    'unreachable' => 'Geen enkele combinatie van regels kan dit verschil op nul brengen: het valt buiten het bereik van alle regels op deze rekening tot en met de opgegeven datum. Controleer de afschriftdatum en het ingevoerde saldo.',

    'check' => 'Controleren',
    'complete' => 'Afstemming voltooien',
    'complete_unavailable' => 'Tot deze datum is er niets meer vast te zetten — markeer meer regels als verrekend of kies een latere afschriftdatum.',

    'errors' => [
        'choose_account' => 'Kies eerst een rekening.',
        'invalid_balance_date' => 'Voer een geldig afschriftsaldo en datum in.',
        'mismatch' => 'Het afschriftsaldo komt nog niet overeen met het verrekende saldo — pas verrekende regels of het ingevoerde saldo aan totdat het verschil nul is.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Niets om vast te zetten voor deze afschriftdatum.',
        'complete' => 'Afstemming voltooid — :count regel vastgezet.|Afstemming voltooid — :count regels vastgezet.',
    ],
];
