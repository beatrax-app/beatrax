---
title: Default categorization rules — seed set derived from real user data
purpose: Input for Phase 16.1.2.1 planner. Defines the universal-merchant seed
  rules that should ship with the app so a fresh first import actually produces
  categorized transactions instead of a 100% uncategorized triage queue.
derived_from: live snapshot of database/nativephp.sqlite as of 2026-05-27
  (414 transactions across 1 ASN bank + 1 PayPal + 1 ICS card account, all
  uncategorized because categorization_rules is empty).
exclusion_principle: |
  Only universal merchants — brand names, utilities, subscriptions, transport
  operators — that a typical NL household interacts with. EXCLUDED on purpose:
  personal income sources (employer names, pension fund names), P2P payment
  identifiers (`* via Tikkie`, family surnames), and budget/lending apps tied
  to a specific consumer relationship. The user creates those rules by hand.
---

# Seed Categorization Rules — Default Set

Every rule below maps to an existing **global** category from
`Modules/Categorization/Database/Seeders/DefaultCategoryTreeSeeder.php`
(category slugs are stable, foreign-keyed by id at install time).

Field/match semantics (per `Modules/Categorization/Internal/Services/RuleEvaluator.php`):

- `field: counterparty` — case-insensitive substring match against the raw
  `transactions.counterparty_name`. Use for merchant brand names.
- `field: description` — case-insensitive match against `transactions.description`.
  Use when the counterparty name is generic (e.g. "KOSTEN KASOPNAME") or the
  distinguishing token sits in the description.
- `match: equals | starts_with | contains` — `contains` scores `10 + len(value)`;
  `starts_with` scores `50 + len(value)`; `equals` scores `100`. Prefer longer
  literal substrings via `contains` over short brittle prefixes.

The DB triggers on `categorization_rules` enforce the field/match allow-list,
so a typo here will fail loud at seeder run time — not silently land a broken
rule.

## Seed rules

```yaml
# ─── Streaming + entertainment ──────────────────────────────────────
- { category: subscriptions-streaming, field: counterparty, match: contains, value: "Netflix" }
- { category: subscriptions-streaming, field: counterparty, match: contains, value: "Audible" }

# ─── Music subscriptions ────────────────────────────────────────────
- { category: subscriptions-music,     field: counterparty, match: contains, value: "Spotify" }

# ─── Cloud / software / AI subscriptions ────────────────────────────
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "Google Cloud" }
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "Google Workspace" }
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "Google Payment" }
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "Microsoft Payments" }
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "Microsoft" }
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "Cloudflare" }
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "TransIP" }
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "Claude.ai" }
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "Anthropic" }
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "OpenAI" }
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "Augment Code" }
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "GitHub" }
- { category: subscriptions-cloud,     field: counterparty, match: contains, value: "WWW.USE.AI" }

# ─── Memberships / creator platforms ────────────────────────────────
- { category: subscriptions-memberships, field: counterparty, match: contains, value: "Patreon" }
- { category: subscriptions-memberships, field: counterparty, match: contains, value: "Discord" }
- { category: subscriptions-memberships, field: counterparty, match: contains, value: "Fourthwall" }
- { category: subscriptions-memberships, field: counterparty, match: contains, value: "Jagex" }

# ─── Housing — utilities (energy, water) ────────────────────────────
- { category: housing-utilities, field: counterparty, match: contains, value: "Essent" }
- { category: housing-utilities, field: counterparty, match: contains, value: "Eneco" }
- { category: housing-utilities, field: counterparty, match: contains, value: "Vitens" }
- { category: housing-utilities, field: counterparty, match: contains, value: "Vattenfall" }
- { category: housing-utilities, field: counterparty, match: contains, value: "Greenchoice" }

# ─── Housing — internet & phone ─────────────────────────────────────
- { category: housing-internet,  field: counterparty, match: contains, value: "KPN" }
- { category: housing-internet,  field: counterparty, match: contains, value: "Ziggo" }
- { category: housing-internet,  field: counterparty, match: contains, value: "T-Mobile" }
- { category: housing-internet,  field: counterparty, match: contains, value: "Vodafone" }
- { category: housing-internet,  field: counterparty, match: contains, value: "Odido" }

# ─── Groceries ──────────────────────────────────────────────────────
- { category: groceries, field: counterparty, match: contains, value: "Albert Heijn" }
- { category: groceries, field: counterparty, match: contains, value: "Jumbo" }
- { category: groceries, field: counterparty, match: contains, value: "Lidl" }
- { category: groceries, field: counterparty, match: contains, value: "Aldi" }
- { category: groceries, field: counterparty, match: contains, value: "Plus Supermarkt" }
- { category: groceries, field: counterparty, match: contains, value: "Dirk van den Broek" }
- { category: groceries, field: counterparty, match: contains, value: "Picnic" }
- { category: groceries, field: counterparty, match: contains, value: "Flink BV" }
- { category: groceries, field: counterparty, match: contains, value: "Gorillas" }
- { category: groceries, field: counterparty, match: contains, value: "Crisp" }

# ─── Eating out / food delivery ─────────────────────────────────────
- { category: eating-out, field: counterparty, match: contains, value: "Thuisbezorgd" }
- { category: eating-out, field: counterparty, match: contains, value: "Takeaway.com" }
- { category: eating-out, field: counterparty, match: contains, value: "Uber Eats" }
- { category: eating-out, field: counterparty, match: contains, value: "Deliveroo" }
- { category: eating-out, field: counterparty, match: contains, value: "Domino" }
- { category: eating-out, field: counterparty, match: contains, value: "McDonald" }

# ─── Transport — public ─────────────────────────────────────────────
- { category: transport-public, field: counterparty, match: contains, value: "NS Groep" }
- { category: transport-public, field: counterparty, match: contains, value: "NS Reizigers" }
- { category: transport-public, field: counterparty, match: contains, value: "NS-International" }
- { category: transport-public, field: counterparty, match: contains, value: "GVB" }
- { category: transport-public, field: counterparty, match: contains, value: "RET" }
- { category: transport-public, field: counterparty, match: contains, value: "HTM" }
- { category: transport-public, field: counterparty, match: contains, value: "OV-chipkaart" }

# ─── Transport — car / fuel / parking / EV charging ─────────────────
- { category: transport-car,    field: counterparty, match: contains, value: "AYVENS" }
- { category: transport-car,    field: counterparty, match: contains, value: "LeasePlan" }
- { category: transport-car,    field: counterparty, match: contains, value: "ANWB Energie" }
- { category: transport-car,    field: counterparty, match: contains, value: "Flitsmeister" }
- { category: transport-car,    field: counterparty, match: contains, value: "Plug Pay" }
- { category: transport-car,    field: counterparty, match: contains, value: "Allego" }
- { category: transport-car,    field: counterparty, match: contains, value: "Fastned" }
- { category: transport-fuel,   field: counterparty, match: contains, value: "Shell" }
- { category: transport-fuel,   field: counterparty, match: contains, value: "BP " }
- { category: transport-fuel,   field: counterparty, match: contains, value: "Tinq" }
- { category: transport-fuel,   field: counterparty, match: contains, value: "Tango" }

# ─── Cash withdrawal ────────────────────────────────────────────────
# `KOSTEN KASOPNAME` and `GELDMAAT …` are both ICS-card receipt
# strings for cash withdrawals. Match the prefix tokens.
- { category: cash-withdrawal, field: counterparty, match: starts_with, value: "KOSTEN KASOPNAME" }
- { category: cash-withdrawal, field: counterparty, match: starts_with, value: "GELDMAAT" }

# ─── Insurance ──────────────────────────────────────────────────────
- { category: insurance-health,    field: counterparty, match: contains, value: "ASR Ziektekosten" }
- { category: insurance-health,    field: counterparty, match: contains, value: "Zilveren Kruis" }
- { category: insurance-health,    field: counterparty, match: contains, value: "VGZ" }
- { category: insurance-health,    field: counterparty, match: contains, value: "CZ Zorgverzekering" }
- { category: insurance-health,    field: counterparty, match: contains, value: "Menzis" }
- { category: insurance-liability, field: counterparty, match: contains, value: "Univé" }
- { category: insurance-liability, field: counterparty, match: contains, value: "Centraal Beheer" }
- { category: insurance-other,     field: counterparty, match: contains, value: "VCN Verzekeringen" }
- { category: insurance-other,     field: counterparty, match: contains, value: "Allianz" }
- { category: insurance-other,     field: counterparty, match: contains, value: "Nationale-Nederlanden" }

# ─── Donations ──────────────────────────────────────────────────────
# Restricted to clearly-named NL charities; the user adds their own
# personal recurring gifts on top.
- { category: donations, field: counterparty, match: contains, value: "Stichting Dierenlot" }
- { category: donations, field: counterparty, match: contains, value: "Save The Children" }
- { category: donations, field: counterparty, match: contains, value: "Greenpeace" }
- { category: donations, field: counterparty, match: contains, value: "Amnesty" }
- { category: donations, field: counterparty, match: contains, value: "WWF" }
- { category: donations, field: counterparty, match: contains, value: "Oxfam Novib" }
- { category: donations, field: counterparty, match: contains, value: "Artsen zonder Grenzen" }
- { category: donations, field: counterparty, match: contains, value: "KWF" }
- { category: donations, field: counterparty, match: contains, value: "Rode Kruis" }

# ─── Fees & charges (Dutch tax authorities + generic fees) ──────────
- { category: fees, field: counterparty, match: contains, value: "BGHU Belastingen" }
- { category: fees, field: counterparty, match: contains, value: "Belastingdienst" }
- { category: fees, field: counterparty, match: contains, value: "Staatsloterij" }
- { category: fees, field: counterparty, match: contains, value: "CJIB" }
- { category: fees, field: counterparty, match: contains, value: "Bank Charges" }

# ─── Transfers (internal — ICS Cards bulk-settled via iDEAL) ────────
# International Card Services BV bulk-payments are funding hops, not
# expenses. Once Phase 16.1.2.1's IBAN-alias bridge lands, these will
# also be retyped to `transfer_out`/`transfer_in` upstream of the
# categorizer — but the rule below catches the case where the
# alias bridge has not (yet) reclassified the row.
- { category: transfers-internal, field: counterparty, match: contains, value: "International Card Services" }
- { category: transfers-internal, field: description,  match: contains, value: "IDEAL BETALING, DANK U" }
```

## Excluded — left for the user to author by hand

These rows appear in the live DB but are **deliberately not seeded** because
they are personal identifiers, not universal merchants:

| Counterparty (real DB row) | Why excluded |
|----------------------------|--------------|
| `B.M.S. van den Hoeven` | Personal income / employer name. |
| `Stg. Rabobank Pensioenfonds` | Personal pension fund. |
| `C. Verheij` | Family / personal recipient. |
| `Snoek via Tikkie` | Personal P2P payment. |
| `DERD GELD LENDER SPENDER` | Personal budgeting app linked to a specific consumer relationship. |
| Any other surname-shaped counterparty | P2P payment, personal. |

The triage UI surfaces these as "uncategorized — needs a rule" so the user
creates their `income-salary`, `housing-rent`, `transfers-internal`, etc.
rules from real data instead of guessed defaults.

## Acceptance for the planner

After the seed rules ship and re-running the existing import-pipeline against
the user's current DB (or a fixture replay of it):

1. The 16 PayPal `Google Payment Ireland Ltd.` rows are categorized as
   `subscriptions-cloud`.
2. The 45+ ICS `Thuisbezorgd.nl ThuisBezo Utrecht` rows are categorized as
   `eating-out`.
3. The 22+ ICS `Flink BV Amsterdam` rows are categorized as `groceries`.
4. The 11 ICS `KOSTEN KASOPNAME` and 6+ ICS `GELDMAAT ROELANTDREEF …` rows
   are categorized as `cash-withdrawal`.
5. Personal-identifier rows (employer, family, Tikkie) remain uncategorized
   in the triage queue.
6. `transactions.auto_category_provenance` is populated on every
   seed-rule-categorized row so the user can see which rule fired and
   override individually.
