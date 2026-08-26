<?php

declare(strict_types=1);

return [
    'heading' => 'Kontovaluta',
    'intro' => 'Den valuta varje konto är angivet i. Ett nytt konto börjar i basvalutan.',
    'no_accounts' => 'Inga konton ännu.',
    'legend' => 'Valuta för :name',
    'label' => 'Valuta',
    'help' => 'Den valuta detta konto visar sitt saldo i.',
    'save' => 'Spara valuta',
    'saved' => 'Sparat',

    'toast' => [
        'updated' => ':name visas nu i :currency.',
    ],

    'errors' => [
        'unknown' => 'Det är ingen valuta som den här installationen känner till.',
    ],

    'warning' => [
        'intro' => 'Att ändra kontot från :from till :to byter bara etikett. Inget lagrat räknas om eller skrivs om.',
        'baseline' => 'Ingående saldo på :amount står kvar på exakt det beloppet och läses hädanefter som :to.',
        'lines' => 'Kontot innehåller just nu:',
        'reads' => 'Efter ändringen visar kontot sin :to-rad — noll om det inte har något i :to.',
        'confirm' => 'Ändra ändå',
        'keep' => 'Behåll :currency',
    ],
];
