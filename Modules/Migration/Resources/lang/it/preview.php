<?php

declare(strict_types=1);

return [
    'page_title' => "Anteprima dell'importazione",

    'heading' => "Anteprima dell'importazione",
    'subtitle' => 'Rivedi cosa cambierà. Nulla viene salvato finché non confermi.',

    'stats' => [
        'category' => 'Categorie',
        'account' => 'Conti',
        'payee' => 'Controparti',
        'transaction' => 'Transazioni',
        'budget' => 'Mesi di budget',
    ],

    'all_clean' => "Tutto mappato correttamente — qui non c'è nulla da decidere.",

    'nothing_staged' => "Questa esportazione non conteneva nulla da importare — qui non c'è nulla da confermare.",

    'discarded' => "Hai scartato questa importazione, quindi qui non c'è più nulla in anteprima.",
    'discarded_link' => 'Avvia una nuova importazione',

    'groups' => [
        'conflict' => 'Richiede una tua decisione',
        'extra' => 'Non importato',
    ],

    'keep_or_take_aria' => 'Mantieni il valore locale o prendi quello di origine per :label',
    'keep_local' => 'Mantieni il locale',
    'take_source' => 'Prendi da origine',

    'footer_note' => 'Questo creerà o aggiornerà i conteggi mostrati sopra nelle tue categorie, nei budget e nel registro.',
    'discard_button' => "Scarta l'importazione",
    'discard_confirm' => "Scartare questa importazione? Tutto ciò che è stato letto dal tuo file di esportazione viene eliminato qui, e per riaverlo devi caricare e analizzare di nuovo l'intero file. Nel registro non è ancora arrivato nulla.",
    'confirm_button' => "Conferma l'importazione",
];
