<?php

declare(strict_types=1);

return [
    'rate_details' => 'Szczegóły kursu',
    'rate_details_for' => 'Szczegóły kursu — :name',

    'converted_to' => 'Przeliczono na :currency',
    'as_of' => 'na dzień :date',
    'as_of_age' => ':date (:ago)',
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
