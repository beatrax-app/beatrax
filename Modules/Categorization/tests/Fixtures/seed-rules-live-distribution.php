<?php

declare(strict_types=1);

/**
 * Live-distribution-sampled fixture for the seed-rule end-to-end test.
 *
 * The shape mirrors the top-100 counterparty_name distinct values from
 * the 2026-05-27 live snapshot (414 transactions across 1 ASN bank +
 * 1 PayPal + 1 ICS card, all uncategorised because
 * `categorization_rules` was empty), with frequency-weighted
 * multiplicity. Each entry represents one transaction occurrence; a
 * counterparty with frequency 16 in the live snapshot appears 16 times
 * in this array.
 *
 * Personal identifiers from the live snapshot are anonymised:
 *   - Employer names → `EMPLOYER_NN`
 *   - Pension funds → `EMPLOYER_PENSION_NN`
 *   - Family / surname-shaped counterparties → `FAMILY_NN`
 *   - P2P / Tikkie payments → `P2P_NN`
 *   - Personal lending / budgeting apps → `P2P_BUDGET_NN`
 * These rows MUST stay uncategorised after the seed runs.
 *
 * Universal merchants keep their real counterparty strings so the seed
 * rules fire on them.
 *
 * The ratio of "matchable universal merchants" to "personal identifiers"
 * mirrors the real-world composition reported in SEED-RULES.md's
 * Acceptance section: 16× Google Payment, 45× Thuisbezorgd, 22× Flink,
 * 11× KOSTEN KASOPNAME, 6× GELDMAAT, plus a long tail of other
 * universal merchants, against ~30 personal-identifier rows.
 *
 * @return list<array{counterparty: string, description: string}>
 */
$rows = [];

$multiply = static function (int $count, string $counterparty, string $description = '') use (&$rows): void {
    for ($i = 0; $i < $count; $i++) {
        $rows[] = ['counterparty' => $counterparty, 'description' => $description];
    }
};

// ─── Universal merchants (categorisable by seed rules) ──────────────

// PayPal-funded Google Play → subscriptions-cloud (16× in snapshot).
$multiply(16, 'Google Payment Ireland Ltd.', 'PayPal payment for Google Play');

// ICS Cards food-delivery line → eating-out (45× in snapshot).
$multiply(45, 'Thuisbezorgd.nl ThuisBezo Utrecht', 'Food delivery via ICS card');

// ICS Cards groceries → groceries (22× in snapshot).
$multiply(22, 'Flink BV Amsterdam', 'Groceries via ICS card');

// ICS Cards cash withdrawal — both forms exercise the starts_with operator.
$multiply(11, 'KOSTEN KASOPNAME 2.00 EUR', 'Cash withdrawal cost on ICS');
$multiply(6, 'GELDMAAT ROELANTDREEF 239 UTRECHT', 'ATM cash withdrawal');

// Streaming, music, cloud / AI subscriptions.
$multiply(3, 'Netflix International B.V.', 'Monthly subscription');
$multiply(2, 'Audible GmbH', 'Audible subscription');
$multiply(3, 'Spotify AB', 'Spotify Premium');
$multiply(2, 'OpenAI LLC', 'ChatGPT Plus');
$multiply(2, 'Anthropic PBC', 'Claude.ai subscription');
$multiply(2, 'GitHub Inc.', 'GitHub Pro');
$multiply(2, 'Microsoft Payments', 'Microsoft 365 subscription');
$multiply(2, 'Patreon Inc.', 'Patreon membership');
$multiply(1, 'Discord Inc.', 'Discord Nitro');

// Utilities, internet & phone, housing.
$multiply(2, 'Eneco Services BV', 'Energy bill');
$multiply(2, 'Vitens NV', 'Water bill');
$multiply(3, 'KPN Mobiel', 'Mobile phone subscription');
$multiply(2, 'Ziggo BV', 'Internet subscription');

// Groceries — other supermarket brands beyond Flink.
$multiply(8, 'Albert Heijn 1234 Utrecht', 'Groceries');
$multiply(5, 'Jumbo Supermarkten Utrecht', 'Groceries');
$multiply(3, 'Lidl Nederland Utrecht', 'Groceries');
$multiply(2, 'Picnic BV', 'Online groceries');

// Eating out — other delivery brands beyond Thuisbezorgd.
$multiply(2, 'Uber Eats', 'Food delivery');
$multiply(2, 'Deliveroo Netherlands', 'Food delivery');
$multiply(1, 'McDonald\'s Restaurants NL', 'Fast food');

// Public transport.
$multiply(4, 'NS Groep NV', 'Train ticket');
$multiply(2, 'NS-International', 'International train booking');
$multiply(2, 'GVB Activa BV', 'Amsterdam public transport');

// Car / fuel / EV charging.
$multiply(3, 'Shell Tankstation Utrecht', 'Fuel');
$multiply(1, 'BP Nederland', 'Fuel');
$multiply(2, 'Fastned Charging', 'EV charging');
$multiply(1, 'Allego BV', 'EV charging');
$multiply(1, 'AYVENS Lease', 'Lease payment');

// Insurance.
$multiply(2, 'Zilveren Kruis Achmea', 'Health insurance premium');
$multiply(1, 'Centraal Beheer Achmea', 'Liability insurance');
$multiply(1, 'Allianz Nederland', 'Insurance premium');

// Donations.
$multiply(2, 'Stichting Dierenlot', 'Monthly donation');
$multiply(1, 'Greenpeace Nederland', 'Monthly donation');
$multiply(1, 'WWF Nederland', 'Monthly donation');
$multiply(1, 'Artsen zonder Grenzen', 'Donation');

// Fees / Dutch tax authorities.
$multiply(2, 'Belastingdienst', 'Tax payment');
$multiply(1, 'CJIB Leeuwarden', 'Traffic fine');
$multiply(1, 'BGHU Belastingen Utrecht', 'Local tax');

// Internal transfers — ICS bulk-settled iDEAL.
$multiply(2, 'International Card Services BV', 'Monthly card settlement');
// One row exercises the description-field rule for transfers-internal.
$multiply(1, 'ASN Bank', 'IDEAL BETALING, DANK U');

// ─── Personal identifiers (anonymised — MUST stay uncategorised) ────

$multiply(2, 'EMPLOYER_01', 'Salaris mei 2026');
$multiply(1, 'EMPLOYER_PENSION_02', 'Pensioenuitkering');
$multiply(3, 'FAMILY_01', 'Bedankt voor het eten');
$multiply(2, 'FAMILY_02', 'Gas / water afrekening shared');
$multiply(2, 'P2P_01', 'Tikkie aan Snoek');
$multiply(1, 'P2P_02', 'Verjaardag cadeau');
$multiply(2, 'P2P_BUDGET_02', 'Spending budget refill');
// Surname-shaped P2P payments — should not match any seed rule.
$multiply(1, 'B.M.S. van den Hoeven', 'Maandelijkse overboeking');
$multiply(1, 'C. Verheij', 'Gezamenlijke kosten');
$multiply(1, 'Snoek via Tikkie', 'Tikkie betaling');
$multiply(1, 'DERD GELD LENDER SPENDER', 'Budget app top-up');

return $rows;
