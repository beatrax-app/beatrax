<?php

declare(strict_types=1);

return [
    'heading' => 'Account currency',
    'intro' => 'The currency each account is denominated in. A new account starts in the base currency.',
    'no_accounts' => 'No accounts yet.',
    'legend' => 'Currency for :name',
    'label' => 'Currency',
    'help' => 'The denomination this account reports its balance in.',
    'save' => 'Save currency',
    'saved' => 'Saved',

    'toast' => [
        'updated' => ':name now reports in :currency.',
    ],

    'errors' => [
        'unknown' => 'That is not a currency this install knows.',
    ],

    'warning' => [
        'intro' => 'Changing this account from :from to :to relabels it. Nothing stored is converted or rewritten.',
        'baseline' => 'Its starting balance of :amount stays that exact figure and is read as :to from now on.',
        'lines' => 'It currently holds:',
        'reads' => 'After the change this account reports its :to line — zero if it holds nothing in :to.',
        'confirm' => 'Change anyway',
        'keep' => 'Keep :currency',
    ],
];
