<?php

declare(strict_types=1);

return [
    'aria' => 'Wartość netto',
    'heading' => 'Wartość netto',

    'rate_details' => 'Szczegóły kursu',
    'rate_details_for' => 'Szczegóły kursu — :name',

    'across' => 'na :count koncie|na :count kontach|na :count kontach',

    'not_converted' => '· nie przeliczono :count konta — brak dostępnego kursu|· nie przeliczono :count kont — brak dostępnego kursu|· nie przeliczono :count kont — brak dostępnego kursu',
    'no_rate_available' => '· brak dostępnego kursu',

    'toggle_hide' => 'Ukryj',
    'toggle_breakdown' => 'Rozbicie',
    'card_suffix' => '(karta)',

    'converted_to' => 'Przeliczono na :currency',
    'as_of' => 'na dzień :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'kursy na dzień :date, źródło: :source',

    'stale_bundled' => 'Używany jest kurs z dołączonej migawki, mający ponad :count dzień. Włącz odświeżanie online w Ustawieniach, aby mieć aktualne kursy.|Używany jest kurs z dołączonej migawki, mający ponad :count dni. Włącz odświeżanie online w Ustawieniach, aby mieć aktualne kursy.|Używany jest kurs z dołączonej migawki, mający ponad :count dni. Włącz odświeżanie online w Ustawieniach, aby mieć aktualne kursy.',
    'stale_old' => 'Ten kurs ma ponad :count dzień. Najbliższe odświeżenie online go zaktualizuje.|Ten kurs ma ponad :count dni. Najbliższe odświeżenie online go zaktualizuje.|Ten kurs ma ponad :count dni. Najbliższe odświeżenie online go zaktualizuje.',
    'stale_offline' => 'Ten kurs ma ponad :count dzień, a odświeżanie online jest wyłączone. Włącz je w Ustawieniach, aby kurs się zaktualizował.|Ten kurs ma ponad :count dni, a odświeżanie online jest wyłączone. Włącz je w Ustawieniach, aby kurs się zaktualizował.|Ten kurs ma ponad :count dni, a odświeżanie online jest wyłączone. Włącz je w Ustawieniach, aby kurs się zaktualizował.',

    // i18n-review: pl · source_ecb — the value is what this locale's own
    // settings.exchange_rates.online_on already writes, so the card and Settings
    // cannot name the same institution two ways. This language usually
    // abbreviates it EBC, and moving to that means moving both lines.
    'source_ecb' => 'ECB',
    'source_bundled' => 'Dołączona migawka',
    'source_transaction' => 'Zapisany kurs',
    'source_fallback' => 'kursy',
];
