<?php

declare(strict_types=1);

return [
    'aria' => 'Netoväärtus',
    'heading' => 'Netoväärtus',

    'rate_details' => 'Kursi üksikasjad',
    'rate_details_for' => 'Kursi üksikasjad: :name',

    'across' => ':count kontol|:count kontol',

    'not_converted' => '· :count kontot ei teisendatud — kurss puudub|· :count kontot ei teisendatud — kurss puudub',
    'no_rate_available' => '· kurss puudub',

    'toggle_hide' => 'Peida',
    'toggle_breakdown' => 'Jaotus',
    'card_suffix' => '(kaart)',

    'converted_to' => 'Teisendatud valuutasse :currency',
    'as_of' => 'seisuga :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'kursid seisuga :date allikast :source',

    'stale_bundled' => 'Kasutusel on kaasas olev hetktõmmise kurss, mis on üle :count päeva vana. Ajakohaste kursside jaoks lülita seadetes sisse veebist värskendamine.|Kasutusel on kaasas olev hetktõmmise kurss, mis on üle :count päeva vana. Ajakohaste kursside jaoks lülita seadetes sisse veebist värskendamine.',
    'stale_old' => 'See kurss on üle :count päeva vana. Järgmine veebivärskendus uuendab selle.|See kurss on üle :count päeva vana. Järgmine veebivärskendus uuendab selle.',
    'stale_offline' => 'See kurss on üle :count päeva vana ja veebist värskendamine on välja lülitatud. Lülita see seadetes sisse, et kurssi uuendada.|See kurss on üle :count päeva vana ja veebist värskendamine on välja lülitatud. Lülita see seadetes sisse, et kurssi uuendada.',

    // i18n-review: et · source_ecb — the value is what this locale's own
    // settings.exchange_rates.online_on already writes, so the card and Settings
    // cannot name the same institution two ways. This language usually
    // abbreviates it EKP, and moving to that means moving both lines.
    'source_ecb' => 'ECB',
    'source_bundled' => 'Kaasas olev hetktõmmis',
    'source_transaction' => 'Salvestatud kurss',
    'source_fallback' => 'kursid',
];
