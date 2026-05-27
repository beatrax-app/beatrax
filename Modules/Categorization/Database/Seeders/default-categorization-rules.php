<?php

declare(strict_types=1);

/**
 * Default categorization-rule fixture installed per user on first install.
 *
 * Each row carries a category slug (resolved at seed time against the
 * global default category tree where `categories.user_id IS NULL`), the
 * `field` to match against on an incoming transaction, the `match`
 * operator, and the literal `value`.
 *
 * Allowed field values: `merchant`, `description`, `counterparty`.
 * Allowed match values: `equals`, `starts_with`, `contains`.
 * Both allow-lists are enforced at the DB layer by paired BEFORE
 * INSERT / BEFORE UPDATE triggers on `categorization_rules`; a typo in
 * this fixture fails loud at seed time rather than landing a silently-
 * broken rule.
 *
 * The rule set targets universal Dutch-household merchants — streaming
 * services, supermarkets, food-delivery brands, telcos, energy
 * suppliers, transport operators, insurance brands, charities, tax
 * authorities, and the bulk-iDEAL settlement marker used by ICS Cards.
 * Personal identifiers (employer names, family names, P2P / Tikkie
 * payments, personal lending apps) are intentionally excluded — the
 * user authors those rules by hand against their own real data via the
 * triage queue.
 *
 * @return list<array{category: string, field: string, match: string, value: string}>
 */
return [
    // ─── Streaming + entertainment ──────────────────────────────────────
    ['category' => 'subscriptions-streaming', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Netflix'],
    ['category' => 'subscriptions-streaming', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Audible'],

    // ─── Music subscriptions ────────────────────────────────────────────
    ['category' => 'subscriptions-music', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Spotify'],

    // ─── Cloud / software / AI subscriptions ────────────────────────────
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Google Cloud'],
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Google Workspace'],
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Google Payment'],
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Microsoft Payments'],
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Microsoft'],
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Cloudflare'],
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'TransIP'],
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Claude.ai'],
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Anthropic'],
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'OpenAI'],
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Augment Code'],
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'GitHub'],
    ['category' => 'subscriptions-cloud', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'WWW.USE.AI'],

    // ─── Memberships / creator platforms ────────────────────────────────
    ['category' => 'subscriptions-memberships', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Patreon'],
    ['category' => 'subscriptions-memberships', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Discord'],
    ['category' => 'subscriptions-memberships', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Fourthwall'],
    ['category' => 'subscriptions-memberships', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Jagex'],

    // ─── Housing — utilities (energy, water) ────────────────────────────
    ['category' => 'housing-utilities', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Essent'],
    ['category' => 'housing-utilities', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Eneco'],
    ['category' => 'housing-utilities', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Vitens'],
    ['category' => 'housing-utilities', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Vattenfall'],
    ['category' => 'housing-utilities', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Greenchoice'],

    // ─── Housing — internet & phone ─────────────────────────────────────
    ['category' => 'housing-internet', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'KPN'],
    ['category' => 'housing-internet', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Ziggo'],
    ['category' => 'housing-internet', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'T-Mobile'],
    ['category' => 'housing-internet', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Vodafone'],
    ['category' => 'housing-internet', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Odido'],

    // ─── Groceries ──────────────────────────────────────────────────────
    ['category' => 'groceries', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Albert Heijn'],
    ['category' => 'groceries', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Jumbo'],
    ['category' => 'groceries', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Lidl'],
    ['category' => 'groceries', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Aldi'],
    ['category' => 'groceries', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Plus Supermarkt'],
    ['category' => 'groceries', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Dirk van den Broek'],
    ['category' => 'groceries', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Picnic'],
    ['category' => 'groceries', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Flink BV'],
    ['category' => 'groceries', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Gorillas'],
    ['category' => 'groceries', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Crisp'],

    // ─── Eating out / food delivery ─────────────────────────────────────
    ['category' => 'eating-out', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Thuisbezorgd'],
    ['category' => 'eating-out', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Takeaway.com'],
    ['category' => 'eating-out', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Uber Eats'],
    ['category' => 'eating-out', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Deliveroo'],
    ['category' => 'eating-out', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Domino'],
    ['category' => 'eating-out', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'McDonald'],

    // ─── Transport — public ─────────────────────────────────────────────
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'NS Groep'],
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'NS Reizigers'],
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'NS-International'],
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'GVB'],
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'RET'],
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'HTM'],
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'OV-chipkaart'],

    // ─── Transport — car / fuel / parking / EV charging ─────────────────
    ['category' => 'transport-car', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'AYVENS'],
    ['category' => 'transport-car', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'LeasePlan'],
    ['category' => 'transport-car', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'ANWB Energie'],
    ['category' => 'transport-car', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Flitsmeister'],
    ['category' => 'transport-car', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Plug Pay'],
    ['category' => 'transport-car', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Allego'],
    ['category' => 'transport-car', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Fastned'],
    ['category' => 'transport-fuel', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Shell'],
    ['category' => 'transport-fuel', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'BP '],
    ['category' => 'transport-fuel', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Tinq'],
    ['category' => 'transport-fuel', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Tango'],

    // ─── Cash withdrawal ────────────────────────────────────────────────
    // `KOSTEN KASOPNAME` and `GELDMAAT …` are both ICS-card receipt
    // strings for cash withdrawals. Match the prefix tokens.
    ['category' => 'cash-withdrawal', 'field' => 'counterparty', 'match' => 'starts_with', 'value' => 'KOSTEN KASOPNAME'],
    ['category' => 'cash-withdrawal', 'field' => 'counterparty', 'match' => 'starts_with', 'value' => 'GELDMAAT'],

    // ─── Insurance ──────────────────────────────────────────────────────
    ['category' => 'insurance-health', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'ASR Ziektekosten'],
    ['category' => 'insurance-health', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Zilveren Kruis'],
    ['category' => 'insurance-health', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'VGZ'],
    ['category' => 'insurance-health', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'CZ Zorgverzekering'],
    ['category' => 'insurance-health', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Menzis'],
    ['category' => 'insurance-liability', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Univé'],
    ['category' => 'insurance-liability', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Centraal Beheer'],
    ['category' => 'insurance-other', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'VCN Verzekeringen'],
    ['category' => 'insurance-other', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Allianz'],
    ['category' => 'insurance-other', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Nationale-Nederlanden'],

    // ─── Donations ──────────────────────────────────────────────────────
    // Restricted to clearly-named NL charities; the user adds their own
    // personal recurring gifts on top.
    ['category' => 'donations', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Stichting Dierenlot'],
    ['category' => 'donations', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Save The Children'],
    ['category' => 'donations', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Greenpeace'],
    ['category' => 'donations', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Amnesty'],
    ['category' => 'donations', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'WWF'],
    ['category' => 'donations', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Oxfam Novib'],
    ['category' => 'donations', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Artsen zonder Grenzen'],
    ['category' => 'donations', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'KWF'],
    ['category' => 'donations', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Rode Kruis'],

    // ─── Fees & charges (Dutch tax authorities + generic fees) ──────────
    ['category' => 'fees', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'BGHU Belastingen'],
    ['category' => 'fees', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Belastingdienst'],
    ['category' => 'fees', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Staatsloterij'],
    ['category' => 'fees', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'CJIB'],
    ['category' => 'fees', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Bank Charges'],

    // ─── Transfers (internal — ICS Cards bulk-settled via iDEAL) ────────
    // International Card Services BV bulk-payments are funding hops, not
    // expenses. The IBAN-alias bridge retypes these to
    // `transfer_out` / `transfer_in` upstream of the categoriser; the
    // rule below catches the case where the alias bridge has not
    // reclassified the row.
    ['category' => 'transfers-internal', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'International Card Services'],
    ['category' => 'transfers-internal', 'field' => 'description', 'match' => 'contains', 'value' => 'IDEAL BETALING, DANK U'],
];
