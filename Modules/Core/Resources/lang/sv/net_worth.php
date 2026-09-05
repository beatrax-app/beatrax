<?php

declare(strict_types=1);

return [
    'aria' => 'Nettoförmögenhet',
    'heading' => 'Nettoförmögenhet',

    'rate_details' => 'Kursdetaljer',
    'rate_details_for' => 'Kursdetaljer för :name',

    'across' => 'fördelat på :count konto|fördelat på :count konton',

    'not_converted' => '· :count konto omräknades inte — ingen kurs tillgänglig|· :count konton omräknades inte — ingen kurs tillgänglig',
    'no_rate_available' => '· ingen kurs tillgänglig',

    'toggle_hide' => 'Dölj',
    'toggle_breakdown' => 'Fördelning',
    'card_suffix' => '(kort)',

    'converted_to' => 'Omräknat till :currency',
    'as_of' => 'per :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'kurser per :date från :source',

    'stale_bundled' => 'En medföljande ögonblickskurs som är mer än :count dag gammal används. Slå på hämtning online i Inställningar för aktuella kurser.|En medföljande ögonblickskurs som är mer än :count dagar gammal används. Slå på hämtning online i Inställningar för aktuella kurser.',
    'stale_old' => 'Den här kursen är mer än :count dag gammal. Nästa hämtning online uppdaterar den.|Den här kursen är mer än :count dagar gammal. Nästa hämtning online uppdaterar den.',
    'stale_offline' => 'Den här kursen är mer än :count dag gammal, och hämtning online är avstängd. Slå på den i Inställningar för att uppdatera kursen.|Den här kursen är mer än :count dagar gammal, och hämtning online är avstängd. Slå på den i Inställningar för att uppdatera kursen.',

    'source_ecb' => 'ECB',
    'source_bundled' => 'Medföljande ögonblicksbild',
    'source_transaction' => 'Registrerad kurs',
    'source_fallback' => 'kurser',
];
