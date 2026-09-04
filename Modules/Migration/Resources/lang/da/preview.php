<?php

declare(strict_types=1);

return [
    'page_title' => 'Forhåndsvis import',

    'heading' => 'Forhåndsvis import',
    'subtitle' => 'Gennemgå, hvad der bliver ændret. Intet gemmes, før du bekræfter.',

    'stats' => [
        'category' => 'Kategorier',
        'account' => 'Konti',
        'payee' => 'Modparter',
        'transaction' => 'Transaktioner',
        'budget' => 'Budgetmåneder',
    ],

    'all_clean' => 'Alt blev tilknyttet rent — der er ikke noget her, du skal tage stilling til.',

    'nothing_staged' => 'Denne eksport indeholdt intet at importere — der er ikke noget at bekræfte her.',

    'discarded' => 'Du kasserede denne import, så der er ikke mere at forhåndsvise her.',
    'discarded_link' => 'Start ny import',

    'groups' => [
        'conflict' => 'Kræver din beslutning',
        'extra' => 'Ikke importeret',
    ],

    'keep_or_take_aria' => 'Behold lokal eller tag fra kilden for :label',
    'keep_local' => 'Behold lokal',
    'take_source' => 'Tag fra kilden',

    'footer_note' => 'Dette opretter eller opdaterer de viste antal ovenfor i dine kategorier, budgetter og transaktioner.',
    'discard_button' => 'Kassér importen',
    'discard_confirm' => 'Vil du kassere denne import? Alt, der er læst ud af din eksportfil, bliver slettet her, og for at få det tilbage skal du uploade og gennemgå hele filen igen. Der er endnu ikke havnet noget blandt dine transaktioner.',
    'confirm_button' => 'Bekræft importen',
];
