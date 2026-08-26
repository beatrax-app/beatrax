<?php

declare(strict_types=1);

return [
    'heading' => 'Kontowährung',
    'intro' => 'Die Währung, auf die jedes Konto lautet. Ein neues Konto beginnt in der Basiswährung.',
    'no_accounts' => 'Noch keine Konten.',
    'legend' => 'Währung für :name',
    'label' => 'Währung',
    'help' => 'Die Währung, in der dieses Konto seinen Saldo ausweist.',
    'save' => 'Währung speichern',
    'saved' => 'Gespeichert',

    'toast' => [
        'updated' => ':name weist jetzt in :currency aus.',
    ],

    'errors' => [
        'unknown' => 'Das ist keine Währung, die diese Installation kennt.',
    ],

    'warning' => [
        'intro' => 'Dieses Konto von :from auf :to zu ändern, benennt es nur um. Nichts Gespeichertes wird umgerechnet oder überschrieben.',
        'baseline' => 'Der Anfangssaldo von :amount bleibt genau dieser Betrag und wird von nun an als :to gelesen.',
        'lines' => 'Dieses Konto enthält derzeit:',
        'reads' => 'Nach der Änderung weist dieses Konto seine :to-Zeile aus — null, wenn es nichts in :to hält.',
        'confirm' => 'Trotzdem ändern',
        'keep' => ':currency behalten',
    ],
];
