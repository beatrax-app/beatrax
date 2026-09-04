<?php

declare(strict_types=1);

return [
    'page_title' => 'Náhled importu',

    'heading' => 'Náhled importu',
    'subtitle' => 'Zkontroluj, co se změní. Dokud to nepotvrdíš, nic se neuloží.',

    'stats' => [
        'category' => 'Kategorie',
        'account' => 'Účty',
        'payee' => 'Protistrany',
        'transaction' => 'Transakce',
        'budget' => 'Měsíce rozpočtu',
    ],

    'all_clean' => 'Všechno se namapovalo čistě — není tu nic k rozhodnutí.',

    'nothing_staged' => 'Tento export neobsahoval nic k importu — není tu co potvrzovat.',

    'discarded' => 'Tento import jsi zahodil, takže tu už není co prohlížet.',
    'discarded_link' => 'Spustit nový import',

    'groups' => [
        'conflict' => 'Vyžaduje tvé rozhodnutí',
        'extra' => 'Neimportováno',
    ],

    'keep_or_take_aria' => 'Ponechat místní, nebo převzít zdrojové — :label',
    'keep_local' => 'Ponechat místní',
    'take_source' => 'Převzít zdrojové',

    'footer_note' => 'Vytvoří nebo aktualizuje to počty uvedené výše v tvých kategoriích, rozpočtech a knize.',
    'discard_button' => 'Zahodit import',
    'discard_confirm' => 'Zahodit tento import? Všechno, co se z tvého souboru s exportem načetlo, se tady smaže a zpátky to dostaneš jen tak, že celý soubor znovu nahraješ a necháš zpracovat. Do knihy zatím nic nedošlo.',
    'confirm_button' => 'Potvrdit import',
];
