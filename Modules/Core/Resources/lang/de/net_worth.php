<?php

declare(strict_types=1);

return [
    'aria' => 'Nettovermögen',
    'heading' => 'Nettovermögen',

    'rate_details' => 'Kursdetails',
    'rate_details_for' => 'Kursdetails für :name',

    'across' => 'auf :count Konto|auf :count Konten',

    'not_converted' => '· :count Konto nicht umgerechnet — kein Kurs verfügbar|· :count Konten nicht umgerechnet — kein Kurs verfügbar',
    'no_rate_available' => '· kein Kurs verfügbar',

    'toggle_hide' => 'Ausblenden',
    'toggle_breakdown' => 'Aufschlüsselung',
    'card_suffix' => '(Karte)',

    'converted_to' => 'Umgerechnet in :currency',
    'as_of' => 'Stand :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'Kurse Stand :date von :source',

    'stale_bundled' => 'Es wird der Kurs aus einer mitgelieferten Momentaufnahme verwendet, die älter als :count Tag ist. Aktiviere in den Einstellungen die Online-Aktualisierung für aktuelle Kurse.|Es wird der Kurs aus einer mitgelieferten Momentaufnahme verwendet, die älter als :count Tage ist. Aktiviere in den Einstellungen die Online-Aktualisierung für aktuelle Kurse.',
    'stale_old' => 'Dieser Kurs ist älter als :count Tag. Die nächste Online-Aktualisierung bringt ihn auf den neuesten Stand.|Dieser Kurs ist älter als :count Tage. Die nächste Online-Aktualisierung bringt ihn auf den neuesten Stand.',
    'stale_offline' => 'Dieser Kurs ist älter als :count Tag, und die Online-Aktualisierung ist aus. Aktiviere sie in den Einstellungen, um ihn zu aktualisieren.|Dieser Kurs ist älter als :count Tage, und die Online-Aktualisierung ist aus. Aktiviere sie in den Einstellungen, um ihn zu aktualisieren.',

    // i18n-review: de · source_ecb — the value is what this locale's own
    // settings.exchange_rates.online_on already writes, so the card and Settings
    // cannot name the same institution two ways. This language usually
    // abbreviates it EZB, and moving to that means moving both lines.
    'source_ecb' => 'ECB',
    'source_bundled' => 'Mitgelieferte Momentaufnahme',
    'source_transaction' => 'Erfasster Kurs',
    'source_fallback' => 'Kurse',
];
