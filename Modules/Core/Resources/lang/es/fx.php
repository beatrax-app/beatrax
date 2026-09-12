<?php

declare(strict_types=1);

return [
    'rate_details' => 'Detalles del tipo de cambio',
    'rate_details_for' => 'Detalles del tipo de cambio de :name',

    'converted_to' => 'Convertido a :currency',
    'as_of' => 'a fecha de :date',
    'as_of_age' => ':date (:ago)',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'tipos a fecha de :date, fuente: :source',
    'rates_not_recorded' => 'tipos no registrados — este resultado se guardó sin ellos',

    'stale_bundled' => 'Se está usando un tipo de cambio incluido en la app con más de :count día. Activa la actualización en línea en Ajustes para tener tipos actuales.|Se está usando un tipo de cambio incluido en la app con más de :count días. Activa la actualización en línea en Ajustes para tener tipos actuales.',
    'stale_old' => 'Este tipo de cambio tiene más de :count día. La próxima actualización en línea lo renovará.|Este tipo de cambio tiene más de :count días. La próxima actualización en línea lo renovará.',
    'stale_offline' => 'Este tipo de cambio tiene más de :count día y la actualización en línea está desactivada. Actívala en Ajustes para renovarlo.|Este tipo de cambio tiene más de :count días y la actualización en línea está desactivada. Actívala en Ajustes para renovarlo.',

    'source_ecb' => 'BCE',
    'source_bundled' => 'Instantánea incluida',
    'source_transaction' => 'Tipo registrado',
    'source_fallback' => 'tipos',
];
