<?php

declare(strict_types=1);

// thin-history-category-fallback: a €95.00 charge to a merchant with only
// two prior samples (< the 5-sample per-counterparty minimum). Per D-06 the
// large-vs-typical detector falls back to the per-category percentile path
// rather than a noisy 2-sample per-merchant baseline. The category history
// (other 'groceries' merchants) establishes the typical band; €95 is well
// above it, so `large` fires via the category fallback.

return [
    'settings' => [
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ],
    'history' => [
        // Thin per-merchant history: only two 'buurtsuper' samples.
        ['counterparty' => 'buurtsuper', 'category' => 'groceries', 'amount_minor' => -2200, 'currency' => 'EUR', 'posted_at' => '2026-04-15'],
        ['counterparty' => 'buurtsuper', 'category' => 'groceries', 'amount_minor' => -2600, 'currency' => 'EUR', 'posted_at' => '2026-05-15'],
        // Richer per-category history establishes the groceries band.
        ['counterparty' => 'albert-heijn', 'category' => 'groceries', 'amount_minor' => -3400, 'currency' => 'EUR', 'posted_at' => '2026-02-02'],
        ['counterparty' => 'albert-heijn', 'category' => 'groceries', 'amount_minor' => -2890, 'currency' => 'EUR', 'posted_at' => '2026-03-04'],
        ['counterparty' => 'jumbo', 'category' => 'groceries', 'amount_minor' => -3100, 'currency' => 'EUR', 'posted_at' => '2026-03-20'],
        ['counterparty' => 'jumbo', 'category' => 'groceries', 'amount_minor' => -2750, 'currency' => 'EUR', 'posted_at' => '2026-04-22'],
    ],
    'transaction' => [
        'counterparty' => 'buurtsuper',
        'category' => 'groceries',
        'amount_minor' => -9500,
        'currency' => 'EUR',
        'posted_at' => '2026-06-15',
        'direction' => 'expense',
        'on_recurring_series' => false,
    ],
    'expected' => [
        'reasons' => ['large'],
    ],
];
