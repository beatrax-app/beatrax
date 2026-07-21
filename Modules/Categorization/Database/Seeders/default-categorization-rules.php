<?php

declare(strict_types=1);

// Each row's `field` (merchant/description/counterparty) and `match`
// (equals/starts_with/contains) are both DB-trigger-enforced
// allow-lists. Targets universal Dutch-household merchants only —
// personal identifiers are excluded; the user authors those by hand.
/**
 * @return list<array{category: string, field: string, match: string, value: string}>
 */
return [
    ['category' => 'subscriptions-streaming', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Netflix'],
    ['category' => 'subscriptions-streaming', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Audible'],

    ['category' => 'subscriptions-music', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Spotify'],

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

    ['category' => 'subscriptions-memberships', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Patreon'],
    ['category' => 'subscriptions-memberships', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Discord'],
    ['category' => 'subscriptions-memberships', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Fourthwall'],
    ['category' => 'subscriptions-memberships', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Jagex'],

    ['category' => 'housing-utilities', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Essent'],
    ['category' => 'housing-utilities', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Eneco'],
    ['category' => 'housing-utilities', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Vitens'],
    ['category' => 'housing-utilities', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Vattenfall'],
    ['category' => 'housing-utilities', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Greenchoice'],

    ['category' => 'housing-internet', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'KPN'],
    ['category' => 'housing-internet', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Ziggo'],
    ['category' => 'housing-internet', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'T-Mobile'],
    ['category' => 'housing-internet', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Vodafone'],
    ['category' => 'housing-internet', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Odido'],

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

    ['category' => 'eating-out', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Thuisbezorgd'],
    ['category' => 'eating-out', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Takeaway.com'],
    ['category' => 'eating-out', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Uber Eats'],
    ['category' => 'eating-out', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Deliveroo'],
    ['category' => 'eating-out', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Domino'],
    ['category' => 'eating-out', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'McDonald'],

    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'NS Groep'],
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'NS Reizigers'],
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'NS-International'],
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'GVB'],
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'RET'],
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'HTM'],
    ['category' => 'transport-public', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'OV-chipkaart'],

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

    // `KOSTEN KASOPNAME` and `GELDMAAT …` are both ICS-card receipt
    // strings for cash withdrawals. Match the prefix tokens.
    ['category' => 'cash-withdrawal', 'field' => 'counterparty', 'match' => 'starts_with', 'value' => 'KOSTEN KASOPNAME'],
    ['category' => 'cash-withdrawal', 'field' => 'counterparty', 'match' => 'starts_with', 'value' => 'GELDMAAT'],

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

    ['category' => 'fees', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'BGHU Belastingen'],
    ['category' => 'fees', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Belastingdienst'],
    ['category' => 'fees', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Staatsloterij'],
    ['category' => 'fees', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'CJIB'],
    ['category' => 'fees', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'Bank Charges'],

    // ICS bulk-payments are funding hops, not expenses; this rule
    // catches the case where the IBAN-alias bridge has not already
    // retyped the row to transfer_out/transfer_in upstream.
    ['category' => 'transfers-internal', 'field' => 'counterparty', 'match' => 'contains', 'value' => 'International Card Services'],
    ['category' => 'transfers-internal', 'field' => 'description', 'match' => 'contains', 'value' => 'IDEAL BETALING, DANK U'],
];
