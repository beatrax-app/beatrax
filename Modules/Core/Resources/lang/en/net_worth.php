<?php

declare(strict_types=1);

return [
    'aria' => 'Net worth',
    'heading' => 'Net worth',

    'rate_details' => 'Rate details',
    'rate_details_for' => 'Rate details for :name',

    'across' => 'across :count account|across :count accounts',

    'not_converted' => '· :count balance not converted — no rate available|· :count balances not converted — no rate available',
    'no_rate_available' => '· no rate available',

    'toggle_hide' => 'Hide',
    'toggle_breakdown' => 'Breakdown',
    'card_suffix' => '(card)',

    'converted_to' => 'Converted to :currency',
    'as_of' => 'as of :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'rates as of :date from :source',

    'stale_bundled' => 'Using a bundled snapshot rate more than :count day old. Enable online refresh in Settings for current rates.|Using a bundled snapshot rate more than :count days old. Enable online refresh in Settings for current rates.',
    'stale_old' => 'This rate is more than :count day old. The next online refresh will update it.|This rate is more than :count days old. The next online refresh will update it.',
    'stale_offline' => 'This rate is more than :count day old, and online refresh is off. Turn it on in Settings to update it.|This rate is more than :count days old, and online refresh is off. Turn it on in Settings to update it.',

    'source_ecb' => 'ECB',
    'source_bundled' => 'Bundled snapshot',
    'source_transaction' => 'Recorded rate',
    'source_fallback' => 'rates',
];
