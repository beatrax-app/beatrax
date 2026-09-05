<?php

declare(strict_types=1);

return [
    'aria' => 'Património líquido',
    'heading' => 'Património líquido',

    'rate_details' => 'Detalhes da taxa de câmbio',
    'rate_details_for' => 'Detalhes da taxa de câmbio de :name',

    'across' => 'em :count conta|em :count contas',

    'not_converted' => '· :count conta não convertida — não há taxa disponível|· :count contas não convertidas — não há taxa disponível',
    'no_rate_available' => '· não há taxa disponível',

    'toggle_hide' => 'Ocultar',
    'toggle_breakdown' => 'Repartição',
    'card_suffix' => '(cartão)',

    'converted_to' => 'Convertido para :currency',
    'as_of' => 'à data de :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'taxas à data de :date, fonte: :source',

    'stale_bundled' => 'Está a ser usada uma taxa de um instantâneo incluído na aplicação com mais de :count dia. Ativa a atualização online nas Definições para teres taxas atuais.|Está a ser usada uma taxa de um instantâneo incluído na aplicação com mais de :count dias. Ativa a atualização online nas Definições para teres taxas atuais.',
    'stale_old' => 'Esta taxa tem mais de :count dia. A próxima atualização online vai renová-la.|Esta taxa tem mais de :count dias. A próxima atualização online vai renová-la.',
    'stale_offline' => 'Esta taxa tem mais de :count dia e a atualização online está desligada. Liga-a nas Definições para a renovar.|Esta taxa tem mais de :count dias e a atualização online está desligada. Liga-a nas Definições para a renovar.',

    'source_ecb' => 'BCE',
    'source_bundled' => 'Instantâneo incluído',
    'source_transaction' => 'Taxa registada',
    'source_fallback' => 'taxas',
];
