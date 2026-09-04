<?php

declare(strict_types=1);

return [
    'page_title' => 'Förhandsgranska import',

    'heading' => 'Förhandsgranska import',
    'subtitle' => 'Granska vad som kommer att ändras. Ingenting sparas förrän du bekräftar.',

    'stats' => [
        'category' => 'Kategorier',
        'account' => 'Konton',
        'payee' => 'Motparter',
        'transaction' => 'Transaktioner',
        'budget' => 'Budgetmånader',
    ],

    'all_clean' => 'Allt kopplades rent — det finns inget här för dig att ta ställning till.',

    'nothing_staged' => 'Den här exporten innehöll inget att importera — det finns inget att bekräfta här.',

    'discarded' => 'Du kastade den här importen, så det finns inget kvar att förhandsgranska här.',
    'discarded_link' => 'Starta ny import',

    'groups' => [
        'conflict' => 'Kräver ditt beslut',
        'extra' => 'Inte importerat',
    ],

    'keep_or_take_aria' => 'Behåll lokalt eller ta från källan för :label',
    'keep_local' => 'Behåll lokalt',
    'take_source' => 'Ta från källan',

    'footer_note' => 'Det här skapar eller uppdaterar antalen ovan bland dina kategorier, budgetar och transaktioner.',
    'discard_button' => 'Kasta importen',
    'discard_confirm' => 'Vill du kasta den här importen? Allt som lästs ur din exportfil raderas här, och för att få tillbaka det måste du ladda upp och tolka hela filen igen. Ingenting har hamnat bland dina transaktioner än.',
    'confirm_button' => 'Bekräfta importen',
];
