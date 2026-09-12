<?php

declare(strict_types=1);

return [
    'rate_details' => 'Detaily kurzu',
    'rate_details_for' => 'Detaily kurzu: :name',

    'converted_to' => 'Převedeno na :currency',
    'as_of' => 'k :date',
    'as_of_age' => ':date (:ago)',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'kurzy k :date ze zdroje :source',
    'rates_not_recorded' => 'kurzy nejsou zaznamenány — tento výsledek byl uložen bez nich',

    'stale_bundled' => 'Používá se přibalený snímek kurzů starší než :count den. Pro aktuální kurzy zapni v Nastavení online obnovování.|Používá se přibalený snímek kurzů starší než :count dny. Pro aktuální kurzy zapni v Nastavení online obnovování.|Používá se přibalený snímek kurzů starší než :count dnů. Pro aktuální kurzy zapni v Nastavení online obnovování.',
    'stale_old' => 'Tento kurz je starší než :count den. Příští online obnovení ho aktualizuje.|Tento kurz je starší než :count dny. Příští online obnovení ho aktualizuje.|Tento kurz je starší než :count dnů. Příští online obnovení ho aktualizuje.',
    'stale_offline' => 'Tento kurz je starší než :count den a online obnovování je vypnuté. Zapni ho v Nastavení, aby se kurz aktualizoval.|Tento kurz je starší než :count dny a online obnovování je vypnuté. Zapni ho v Nastavení, aby se kurz aktualizoval.|Tento kurz je starší než :count dnů a online obnovování je vypnuté. Zapni ho v Nastavení, aby se kurz aktualizoval.',

    'source_ecb' => 'ECB',
    'source_bundled' => 'Přibalený snímek',
    'source_transaction' => 'Zaznamenaný kurz',
    'source_fallback' => 'kurzy',
];
