---
phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
plan: 01b
type: execute
wave: 0
depends_on: ["05-01"]
files_modified:
  - Modules/Chains/composer.json
  - Modules/Chains/Providers/ChainsServiceProvider.php
  - Modules/Chains/tests/Pest.php
  - Modules/Chains/tests/TestCase.php
  - Modules/Chains/tests/Unit/SmokeTest.php
  - Modules/Chains/tests/Unit/FixtureParseSmokeTest.php
  - Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml
  - Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf
  - Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv
  - Modules/Chains/tests/fixtures/scenario-1/scenario-1.md
  - Modules/Chains/tests/fixtures/scenario-1/scenario-1-overpaid.json
  - Modules/Chains/tests/fixtures/scenario-1/scenario-1-underpaid.json
  - Modules/Transfers/Public/Services/PairLookup.php
  - Modules/Transfers/Providers/TransfersServiceProvider.php
  - Modules/Transfers/tests/Feature/PairLookupTest.php
  - scripts/synthesise_phase5_scenario.php
  - bootstrap/providers.php
  - phpunit.xml
  - tests/Pest.php
  - tests/Contracts/BoundaryArchTest.php
  - tests/Feature/HorizonBootsTest.php
autonomous: false
requirements:
  - CHN-07
user_setup: []

must_haves:
  truths:
    - "Modules/Chains/ bounded module exists with composer.json + ServiceProvider registered in bootstrap/providers.php"
    - "Synthesised cross-source fixture trio (ASN CAMT.053 + ICS PDF + PayPal CSV) plus clean/overpaid/underpaid overlay manifests committed under Modules/Chains/tests/fixtures/scenario-1/"
    - "Modules/Transfers/Public/Services/PairLookup with isPaired()/partnerId() is callable from outside the Transfers module"
    - "BoundaryArchTest extends with three new invariants (D-84 no resolver writes transactions, D-95 only state machine mutates card_statements.state, Cache facade carve-out for ResolveChainLinksJob)"
    - "HorizonBootsTest uses an EXPLICIT skip predicate (issue #9 fix — pest()->skip(fn () => !isRedisReachable(...), 'Redis container required — run docker start diederik-redis')) — NEVER ->skipOnFailure() which hides the real Wave 0 precondition failure"
    - "Redis-reachability smoke test surfaces the precondition in the test output, not as a silent skip"
    - "**D-107:** Synthesised cross-source fixture trio (ASN CAMT.053 + ICS PDF + PayPal CSV) under scenario-1/ — see 05-CONTEXT.md `<decisions>` for full text"
    - "**D-109:** `Modules/Chains/` is the new bounded module; Public surface ships day one; Internal/ holds resolvers + state machine + job + SFCs — see 05-CONTEXT.md `<decisions>` for full text"
  artifacts:
    - path: Modules/Chains/composer.json
      provides: "Chains module manifest"
      contains: "diederik/chains"
    - path: Modules/Chains/Providers/ChainsServiceProvider.php
      provides: "Module bootstrap (Livewire components, routes, views, migrations)"
      exports: ["ChainsServiceProvider"]
    - path: Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml
      provides: "Synthesised ASN bulk-iDEAL fixture"
      min_lines: 30
    - path: Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf
      provides: "Synthesised ICS PDF statement with N transactions"
      min_lines: 1
    - path: Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv
      provides: "Synthesised PayPal CSV with deterministic + D-106 General Withdrawal NL rows"
      min_lines: 10
    - path: Modules/Chains/tests/fixtures/scenario-1/scenario-1.md
      provides: "Fixture record documenting totals, counts, IBANs, per-variant deltas"
      min_lines: 25
    - path: Modules/Transfers/Public/Services/PairLookup.php
      provides: "Public read API over transactions.pair_transaction_id (D-110)"
      exports: ["isPaired", "partnerId"]
    - path: scripts/synthesise_phase5_scenario.php
      provides: "Composer-dep-free CLI fixture synthesizer (D-108)"
      contains: "#!/usr/bin/env php"
  key_links:
    - from: bootstrap/providers.php
      to: Modules\\Chains\\Providers\\ChainsServiceProvider
      via: "providers array entry"
      pattern: "ChainsServiceProvider::class"
    - from: Modules/Chains/Providers/ChainsServiceProvider.php
      to: Modules/Chains/Database/Migrations
      via: "loadMigrationsFrom"
      pattern: "loadMigrationsFrom"
    - from: tests/Contracts/BoundaryArchTest.php
      to: Modules\\Chains\\Internal
      via: "pest-arch toOnlyBeUsedIn rule"
      pattern: "Modules\\\\Chains\\\\Internal"
---

<objective>
Wave 0 (module + fixtures half): scaffold the new `Modules/Chains/` bounded module skeleton, commit the synthesised cross-source matching fixture trio (D-107/D-108), promote `Modules/Transfers/Public/Services/PairLookup` (D-110), extend `BoundaryArchTest` with the three Phase 5 invariants (D-84 / D-95 / Cache facade carve-out), wire `phpunit.xml` + `tests/Pest.php` for Chains test discovery, and ship the `HorizonBootsTest` that uses an EXPLICIT skip predicate (issue #9 fix) instead of the silent `->skipOnFailure()` that hides Wave 0 precondition failures.

This is the companion to `05-01` (infrastructure half — Horizon install, failed_jobs migration, Docker Redis, PROJECT.md/README amendments). Split per issue #5 (24 files in old 05-01 exceeded the 15-file blocker threshold).

Purpose: Wave 1's schema migrations need the `Modules/Chains/` module skeleton to live in. Wave 2's resolver tests need the synthesised fixture trio. Wave 3's PaypalFundingResolver consumes `PairLookup`. The three BoundaryArchTest invariants enforce the resolver-invariant guarantees (D-84 / D-95) and the single permitted Cache facade carve-out (D-101).

Output:
- New module skeleton at `Modules/Chains/` (composer.json + ServiceProvider + tests/Pest.php + tests/TestCase.php + placeholder directories).
- Synthesised fixture trio under `Modules/Chains/tests/fixtures/scenario-1/` (ASN CAMT.053 + ICS PDF + PayPal CSV) + clean/overpaid/underpaid overlay manifests.
- `scripts/synthesise_phase5_scenario.php` committed in-repo (composer-dep-free).
- `Modules/Transfers/Public/Services/PairLookup.php` (D-110 promotion) with isPaired() and partnerId() methods + binding in TransfersServiceProvider + smoke test.
- Three new `BoundaryArchTest` rules: D-84 no resolver writes transactions, D-95 only CardStatementStateMachine mutates card_statements.state, Cache facade carve-out (allow-list `Modules\Chains\Internal\Jobs\ResolveChainLinksJob`).
- `phpunit.xml` + `tests/Pest.php` extended with the Chains test discovery row.
- `tests/Feature/HorizonBootsTest.php` with explicit skip predicate (issue #9 fix).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/REQUIREMENTS.md
@.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-CONTEXT.md
@.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-RESEARCH.md
@.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-PATTERNS.md
@.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-VALIDATION.md
@.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-01-PLAN.md

# Existing analogs the executor will mirror verbatim:
@Modules/Transfers/composer.json
@Modules/Transfers/Providers/TransfersServiceProvider.php
@Modules/Transfers/tests/Pest.php
@Modules/Transfers/tests/TestCase.php
@Modules/Transfers/Internal/Listeners/PairTransferCandidates.php
@tests/Contracts/BoundaryArchTest.php
@bootstrap/providers.php
@composer.json
@scripts/anonymize_paypal_csv.php
@scripts/generate_tiny_ics_pdf.php
@scripts/anonymize_ics_text.php

<interfaces>
<!-- Public surface this wave creates. Downstream waves consume these. -->

From Modules/Transfers/Public/Services/PairLookup.php (NEW — D-110 promotion):
```php
namespace Modules\Transfers\Public\Services;

final class PairLookup
{
    public function __construct(private readonly \Illuminate\Database\DatabaseManager $db) {}
    public function isPaired(int $txId, \Modules\Core\Models\User $user): bool;
    public function partnerId(int $txId, \Modules\Core\Models\User $user): ?int;
}
```

From Modules/Chains/Providers/ChainsServiceProvider.php (skeleton — concrete bindings land in later waves):
```php
namespace Modules\Chains\Providers;

final class ChainsServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void;
    public function boot(\Livewire\LivewireManager $livewire): void;
}
```

From scripts/synthesise_phase5_scenario.php (NEW — D-108):
```php
// CLI script. Run: php scripts/synthesise_phase5_scenario.php
// Writes: Modules/Chains/tests/fixtures/scenario-1/{asn-camt053.xml, ics-statement.pdf, paypal-activity.csv, scenario-1.md, scenario-1-overpaid.json, scenario-1-underpaid.json}
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Modules/Chains skeleton + composer autoload + ServiceProvider registration + phpunit/Pest wire-up + SmokeTest</name>
  <files>Modules/Chains/composer.json, Modules/Chains/Providers/ChainsServiceProvider.php, Modules/Chains/tests/Pest.php, Modules/Chains/tests/TestCase.php, Modules/Chains/tests/Unit/SmokeTest.php, bootstrap/providers.php, phpunit.xml, tests/Pest.php, composer.json</files>
  <read_first>
    - Modules/Transfers/composer.json (manifest analog — copy verbatim with namespace swap)
    - Modules/Transfers/Providers/TransfersServiceProvider.php (minimal listener-only ServiceProvider shape)
    - Modules/Categorization/Providers/CategorizationServiceProvider.php (canonical full-feature ServiceProvider with migrations + routes + views + Livewire + DI bindings)
    - Modules/Transfers/tests/Pest.php and Modules/Transfers/tests/TestCase.php (test bootstrap analog — copy verbatim with namespace swap)
    - phpunit.xml (current testsuite entries)
    - tests/Pest.php (existing module wire-up map)
    - bootstrap/providers.php (current provider order — ChainsServiceProvider will be added here)
    - composer.json (autoload-dev psr-4 block — extend with Modules\\Chains\\Tests)
    - .planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-PATTERNS.md sections: "Modules/Chains/composer.json", "Modules/Chains/Providers/ChainsServiceProvider.php"
  </read_first>
  <behavior>
    - Test 1: `Modules\Chains\Providers\ChainsServiceProvider` is auto-discovered and registered when present in `bootstrap/providers.php`; `php artisan about` lists Chains in the providers section.
    - Test 2: `Modules/Chains/tests/Pest.php` loads via the standard Pest discovery; an empty `tests/Unit/SmokeTest.php` placeholder ("it loads" → `expect(true)->toBeTrue()`) runs green via `vendor/bin/pest --filter "Chains"`.
    - Test 3: `composer dump-autoload` runs without errors after the new Modules\\Chains\\Tests autoload entry is added.
    - Test 4: `phpunit.xml` contains three new testsuites named `ChainsUnit`, `ChainsFeature`, `ChainsContracts`.
  </behavior>
  <action>
**Step 1 — Composer autoload-dev extension.**

Modify `composer.json` `autoload-dev.psr-4` to add:
```json
"Modules\\Chains\\Tests\\": "Modules/Chains/tests/"
```

Run:
```bash
composer dump-autoload
```

**Step 2 — Module skeleton (mirror Modules/Transfers).**

Create `Modules/Chains/composer.json` (verbatim from Transfers with namespace + description swap):
```json
{
    "name": "diederik/chains",
    "description": "Chains module — cross-source funding-chain resolver + ICS bulk-iDEAL decomposer + card_statements model.",
    "type": "laravel-module",
    "license": "proprietary",
    "autoload": {
        "psr-4": {
            "Modules\\Chains\\": ""
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Chains\\Tests\\": "tests/"
        }
    }
}
```

Create `Modules/Chains/Providers/ChainsServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;

final class ChainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Concrete singleton bindings land in Wave 1 (ChainLinkQuery,
        // CardStatementQuery, CardStatementStateMachine, ConfirmChainLink,
        // RejectChainLink) and Wave 4 (Livewire components).
    }

    public function boot(LivewireManager $livewire): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'chains');
        }
        // Livewire component registration moves into this method in Wave 4
        // when chains.chain-review-queue and chains.chain-drawer exist.
        // Per D-109, the Chains module's Public surface is exposed from
        // day one.
    }
}
```

Create empty placeholder directories the provider conditionally loads from:
- `Modules/Chains/Database/Migrations/.gitkeep`
- `Modules/Chains/Routes/.gitkeep`
- `Modules/Chains/Resources/views/.gitkeep`
- `Modules/Chains/Internal/.gitkeep`
- `Modules/Chains/Public/.gitkeep`
- `Modules/Chains/Models/.gitkeep`
- `Modules/Chains/tests/Unit/.gitkeep`
- `Modules/Chains/tests/Feature/.gitkeep`
- `Modules/Chains/tests/Contracts/.gitkeep`
- `Modules/Chains/tests/fixtures/.gitkeep`

Create `Modules/Chains/tests/Pest.php` (verbatim from Transfers with namespace swap):
```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Contracts');

pest()->extend(TestCase::class)->in('Unit');
```

Create `Modules/Chains/tests/TestCase.php`:
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Tests;

use Tests\TestCase as RootTestCase;

abstract class TestCase extends RootTestCase {}
```

Create `Modules/Chains/tests/Unit/SmokeTest.php`:
```php
<?php

declare(strict_types=1);

it('loads the Chains module test harness', function (): void {
    expect(true)->toBeTrue();
});
```

**Step 3 — Register ChainsServiceProvider in `bootstrap/providers.php`.**

Add `use Modules\Chains\Providers\ChainsServiceProvider;` and the array entry. Place alphabetically (after `CategorizationServiceProvider`, before `CoreServiceProvider`):
```php
return [
    App\Providers\HorizonServiceProvider::class,   // from 05-01
    CoreServiceProvider::class,
    LedgerServiceProvider::class,
    IngestionServiceProvider::class,
    ImportServiceProvider::class,
    CategorizationServiceProvider::class,
    ChainsServiceProvider::class,     // NEW
    TransfersServiceProvider::class,
];
```

**Step 4 — Wire `phpunit.xml` + `tests/Pest.php`.**

Add Chains testsuite entries to `phpunit.xml` (mirror existing Transfers entry):
```xml
<testsuite name="ChainsUnit">
    <directory suffix="Test.php">./Modules/Chains/tests/Unit</directory>
</testsuite>
<testsuite name="ChainsFeature">
    <directory suffix="Test.php">./Modules/Chains/tests/Feature</directory>
</testsuite>
<testsuite name="ChainsContracts">
    <directory suffix="Test.php">./Modules/Chains/tests/Contracts</directory>
</testsuite>
```

Add a row to `tests/Pest.php`'s module wire-up loop for `Chains` (matches the documented "per-module Pest.php is documented inert" pattern from STATE.md Phase 04 Plan 03 lessons).
  </action>
  <verify>
    <automated>composer dump-autoload &amp;&amp; vendor/bin/pest --filter "SmokeTest" --testdox</automated>
    <automated>php artisan about 2>&amp;1 | grep -E 'Chains'</automated>
    <automated>test -f Modules/Chains/composer.json &amp;&amp; test -f Modules/Chains/Providers/ChainsServiceProvider.php &amp;&amp; test -f Modules/Chains/tests/Pest.php &amp;&amp; test -f Modules/Chains/tests/TestCase.php</automated>
    <automated>grep -q 'ChainsServiceProvider::class' bootstrap/providers.php</automated>
    <automated>grep -q 'ChainsUnit\|ChainsFeature\|ChainsContracts' phpunit.xml</automated>
  </verify>
  <acceptance_criteria>
    - `Modules/Chains/composer.json` exists with `diederik/chains` name and PSR-4 autoload entry `Modules\\Chains\\`.
    - `Modules/Chains/Providers/ChainsServiceProvider.php` exists with `final class ChainsServiceProvider extends ServiceProvider`.
    - `bootstrap/providers.php` contains `ChainsServiceProvider::class`.
    - `Modules/Chains/tests/Pest.php` and `Modules/Chains/tests/TestCase.php` exist; `Modules/Chains/tests/Unit/SmokeTest.php` runs green via `vendor/bin/pest --filter "SmokeTest"`.
    - `phpunit.xml` contains three new testsuites named `ChainsUnit`, `ChainsFeature`, `ChainsContracts`.
    - Larastan level 10 strict passes with zero NEW errors.
    - Pint formatting clean: `composer format:check` exits 0.
  </acceptance_criteria>
  <done>
    Chains module skeleton exists with empty placeholder dirs, ChainsServiceProvider registered, phpunit.xml + tests/Pest.php discover the new Chains tests, smoke test green.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Synthesised fixture trio + scripts/synthesise_phase5_scenario.php + FixtureParseSmokeTest</name>
  <files>scripts/synthesise_phase5_scenario.php, Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml, Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf, Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv, Modules/Chains/tests/fixtures/scenario-1/scenario-1.md, Modules/Chains/tests/fixtures/scenario-1/scenario-1-overpaid.json, Modules/Chains/tests/fixtures/scenario-1/scenario-1-underpaid.json, Modules/Chains/tests/Unit/FixtureParseSmokeTest.php</files>
  <read_first>
    - scripts/anonymize_paypal_csv.php (shebang + idempotent regex-pass shape)
    - scripts/generate_tiny_ics_pdf.php (PDF byte-stream emit shape)
    - scripts/anonymize_ics_text.php (anonymisation shape)
    - Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv (PayPal CSV layout)
    - Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.md (fixture-record doc shape)
    - Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md (fixture-record analog)
    - Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf (tiny PDF baseline)
    - Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php (target adapter for the synthesised PDF — fixture must parse cleanly)
    - Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php (target adapter — fixture must parse + rollup cleanly)
    - .planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-PATTERNS.md sections: "scripts/synthesise_phase5_scenario.php", "Modules/Chains/tests/fixtures/scenario-1/"
    - .planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-RESEARCH.md "Pattern 7: synthesise_phase5_scenario.php"
    - .planning/phases/04-paypal-ingestion-transfer-detection/04-CONTEXT.md (PayPal D-106 General Withdrawal NL shape)
  </read_first>
  <behavior>
    - Test 1: `php scripts/synthesise_phase5_scenario.php` runs without errors and writes the six fixture files under `Modules/Chains/tests/fixtures/scenario-1/`. Re-running produces byte-identical output (idempotent via seeded `mt_srand(20260516)`).
    - Test 2: The committed `asn-camt053.xml` is parseable by `genkgo/camt` (no XML errors, contains exactly one `<Ntry>` whose `<Amt>` is the sum of the ICS PDF transactions).
    - Test 3: The committed `ics-statement.pdf` is parseable by `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter` (extracted text contains the expected statement totals + N transaction lines).
    - Test 4: The committed `paypal-activity.csv` is parseable by `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter` (HeaderSniffer returns NL profile; rollup walker produces ≥3 logical transactions including one "Bankstorting" / "General Withdrawal" row with inferable destination IBAN matching the ASN account).
    - Test 5: `scenario-1-overpaid.json` and `scenario-1-underpaid.json` overlay manifests carry the locked delta values (+€1.53 / −€2.18).
  </behavior>
  <action>
**Per D-107 / D-108 / RESEARCH Pattern 7: synthesise (do NOT anonymise) the cross-source matching fixture trio.**

The CONTEXT.md locks: 20–25 ICS transactions (planner default: 23 to match RESEARCH chain-depth examples), three reconciliation variants (clean / overpaid / underpaid), and a PayPal trio shape including the D-106 "General Withdrawal NL" row.

**Create `scripts/synthesise_phase5_scenario.php`:**

Shebang line + strict types + docblock (mirror `scripts/anonymize_paypal_csv.php` lines 1-50). The script is composer-dep-free — no FPDF / TCPDF / no XML library. It writes:

1. **`Modules/Chains/tests/fixtures/scenario-1/scenario-1.md`** — fixture record. Document:
   - Header: "Phase 5 scenario-1 — synthesised cross-source fixture (NOT anonymised from real user data)"
   - Generation: "Generated by `scripts/synthesise_phase5_scenario.php`. Re-run produces byte-identical output (seeded `mt_srand(20260516)`)."
   - Empirical contract: ICS period `2026-04-15 → 2026-05-14`, ICS transaction count 23, ICS statement total `€847.32`, ASN bulk-iDEAL date `2026-05-19`, ASN counterparty IBAN `ICS-CARD` (synthetic), PayPal Reference Txn ID chain depth 3.
   - Variants table: `scenario-1-overpaid.json` overlays bulk-iDEAL = `€848.85` (+€1.53); `scenario-1-underpaid.json` overlays = `€845.14` (−€2.18); clean = `€847.32` exactly.
   - Map of D-IDs → fixture features: D-106 General Withdrawal NL row at line 14 of the PayPal CSV; deterministic Reference-Txn-ID chain at lines 8–10; FX-conversion chain at lines 11–13; etc.

2. **`Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml`** — ASN CAMT.053 statement containing ONE entry: a `<Ntry>` with `<CdtDbtInd>DBIT</CdtDbtInd>`, `<Amt Ccy="EUR">847.32</Amt>`, `<BookgDt>2026-05-19</BookgDt>`, `<NtryDtls><TxDtls><RltdPties><Cdtr><Nm>ICS Cards Nederland</Nm></Cdtr><CdtrAcct><Id><Othr><Id>ICS-CARD</Id></Othr></Id></CdtrAcct></RltdPties><RmtInf><Ustrd>iDEAL betaling ICS afschrift 2026-04</Ustrd></RmtInf></TxDtls></NtryDtls>`. Use namespace `urn:iso:std:iso:20022:tech:xsd:camt.053.001.02` (matches the Phase 2 empirical sub-version locked in STATE.md). Include `<GrpHdr>` and a single `<Stmt>` wrapping the entry.

3. **`Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf`** — synthesise via the same raw PDF byte-stream technique as `scripts/generate_tiny_ics_pdf.php`. PDF must contain:
   - Header line: `Periode 15 april 2026 - 14 mei 2026` (matches IcsPdfAdapter parsing per Phase 3 D-51 addendum).
   - Summary block: `Vorig openstaand saldo €0,00`, `Totaal nieuwe uitgaven €847,32`, `Nieuw openstaand saldo €847,32`, `Bestedingslimiet €5.000,00`, `Minimaal te betalen bedrag €847,32`.
   - 23 transaction lines in the empirical ICS layout (`{dd MMM} {dd MMM} {merchant} {amount} Af`), summing to €847.32. Mix in 2 USD-funded FX rows (e.g. `$12.99` → `€12.07`, `$74.43` → `€68.86`) with the two-line FX shape D-35.
   - Statement-end footer.
   The total page footprint stays under 5 KB.

4. **`Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv`** — synthesise a 14-row PayPal CSV in the NL profile (Phase 4 D-58 / Plan 01 locked tokens). Required rows:
   - Header row with the 7-token NL discriminator: `Datum,Tijd,Tijdzone,Omschrijving,Valuta,Transactiereferentie,Reference Txn ID,...` (full column set per PaypalCsvAdapter expectations).
   - 3 logical payments yielding rollup parents — one Express Checkout Payment whose Reference Txn ID chains to a Bank Withdrawal (deterministic D-106 hand-off shape).
   - 1 "General Withdrawal" / "Bankstorting" row (CSV column `Naam = "Bankstorting"`, currency EUR) whose `Reference Txn ID` matches a parent — fuzzy/deterministic resolver target. The row's `Naam` or memo must carry a counterparty IBAN matching the ASN synthetic IBAN `NL57ASNB0123456789` (D-106 close-out — Phase 4 hand-off).
   - 1 FX-conversion chain (4-row chain: USD parent + EUR Bankstorting + EUR Algemene-valutaomrekening + USD Algemene-valutaomrekening — Phase 4 Plan 01 D-60(e) locked shape).
   - 5 standalone payments (mix of EUR Express Checkout Payments — Netflix, Spotify, etc. — so the PayPal funding resolver has fuzzy-match targets).

5. **`Modules/Chains/tests/fixtures/scenario-1/scenario-1-overpaid.json`** — overlay manifest:
   ```json
   {
     "variant": "overpaid",
     "bulk_settle_amount_minor": 84885,
     "delta_minor": 153,
     "expected_card_statement_state": "overpaid",
     "expected_credit_carry_minor": 153,
     "expected_chain_state": "confirmed",
     "tolerance_used": "amount_5eur"
   }
   ```

6. **`Modules/Chains/tests/fixtures/scenario-1/scenario-1-underpaid.json`** — overlay manifest:
   ```json
   {
     "variant": "underpaid",
     "bulk_settle_amount_minor": 84514,
     "delta_minor": -218,
     "expected_card_statement_state": "partially_settled",
     "expected_credit_carry_minor": 0,
     "expected_chain_state": "confirmed",
     "tolerance_used": "amount_5eur"
   }
   ```

The clean variant has no overlay file — base XML/PDF/CSV values are the clean case.

**Idempotency invariant:** The script seeds `mt_srand(20260516)` once at the top. Re-running on a clean filesystem produces byte-identical XML / PDF / CSV / MD outputs. Document this in the script header. The script uses `file_put_contents` + relative paths so it can be run from the project root.

**Smoke test the fixtures parse correctly:** Add `Modules/Chains/tests/Unit/FixtureParseSmokeTest.php` that:
1. Resolves the IcsPdfAdapter from the container, runs it against `scenario-1/ics-statement.pdf`, asserts ≥1 statement summary and 23 entries.
2. Resolves the PaypalCsvAdapter from the container, runs it against `scenario-1/paypal-activity.csv`, asserts ≥3 rolled-up logical transactions including the General Withdrawal hand-off row.
3. Parses `scenario-1/asn-camt053.xml` via `genkgo/camt` directly, asserts exactly one entry whose amount equals €847.32.

The smoke test ensures the fixtures are usable BEFORE Wave 1/2/3 build resolvers against them.
  </action>
  <verify>
    <automated>php scripts/synthesise_phase5_scenario.php &amp;&amp; test -f Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml &amp;&amp; test -f Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf &amp;&amp; test -f Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv &amp;&amp; test -f Modules/Chains/tests/fixtures/scenario-1/scenario-1.md &amp;&amp; test -f Modules/Chains/tests/fixtures/scenario-1/scenario-1-overpaid.json &amp;&amp; test -f Modules/Chains/tests/fixtures/scenario-1/scenario-1-underpaid.json</automated>
    <automated>vendor/bin/pest --filter "FixtureParseSmokeTest"</automated>
    <automated>php -r 'echo md5_file("Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml");' &gt; /tmp/h1.txt; php scripts/synthesise_phase5_scenario.php; php -r 'echo md5_file("Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml");' &gt; /tmp/h2.txt; diff /tmp/h1.txt /tmp/h2.txt</automated>
    <automated>jq -r '.bulk_settle_amount_minor' Modules/Chains/tests/fixtures/scenario-1/scenario-1-overpaid.json | grep -q '^84885$'</automated>
    <automated>jq -r '.bulk_settle_amount_minor' Modules/Chains/tests/fixtures/scenario-1/scenario-1-underpaid.json | grep -q '^84514$'</automated>
  </verify>
  <acceptance_criteria>
    - `scripts/synthesise_phase5_scenario.php` exists, starts with `#!/usr/bin/env php` shebang, calls `declare(strict_types=1)`, and seeds `mt_srand(20260516)`.
    - Running the script twice on a clean filesystem produces byte-identical output (verifiable via diff on MD5 hashes of all six files).
    - `Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml` is valid XML with `urn:iso:std:iso:20022:tech:xsd:camt.053.001.02` namespace and exactly one `<Ntry>` element whose `<Amt>` equals `847.32` (clean variant).
    - `Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf` is a valid PDF (`file Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf` reports `PDF document`) of ≤ 5 KB.
    - `Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv` is a 14-row CSV with the 7-token NL discriminator header AND contains at least one row whose `Naam` column or memo includes `Bankstorting` AND the IBAN `NL57ASNB0123456789`.
    - `scenario-1.md` documents: period dates, transaction count (23), statement total (€847.32), ASN bulk-iDEAL date (2026-05-19), and all three variant deltas with their `card_statement.state` expectations.
    - `FixtureParseSmokeTest` passes — `vendor/bin/pest --filter "FixtureParseSmokeTest"` reports zero failures.
    - `scenario-1-overpaid.json` has `bulk_settle_amount_minor: 84885`, `delta_minor: 153`, `expected_card_statement_state: "overpaid"`.
    - `scenario-1-underpaid.json` has `bulk_settle_amount_minor: 84514`, `delta_minor: -218`, `expected_card_statement_state: "partially_settled"`.
    - The PayPal CSV is parseable by `PaypalCsvAdapter` (asserted by smoke test).
    - The ICS PDF is parseable by `IcsPdfAdapter` (asserted by smoke test) and contains exactly 23 transaction lines summing to €847.32.
    - Larastan level 10 strict passes with zero NEW errors on `scripts/synthesise_phase5_scenario.php` and the smoke test.
    - Pint formatting clean: `composer format:check` exits 0.
  </acceptance_criteria>
  <done>
    `scripts/synthesise_phase5_scenario.php` committed and idempotent. Six fixture files committed under `Modules/Chains/tests/fixtures/scenario-1/`. Smoke test (`FixtureParseSmokeTest`) green — confirms ICS PDF + PayPal CSV + ASN CAMT.053 all parse cleanly under their existing Phase 2/3/4 adapters.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: PairLookup Public promotion + BoundaryArchTest extensions + HorizonBootsTest with explicit skip predicate (issue #9 fix)</name>
  <files>Modules/Transfers/Public/Services/PairLookup.php, Modules/Transfers/Providers/TransfersServiceProvider.php, Modules/Transfers/tests/Feature/PairLookupTest.php, tests/Contracts/BoundaryArchTest.php, tests/Feature/HorizonBootsTest.php</files>
  <read_first>
    - Modules/Transfers/Providers/TransfersServiceProvider.php (current shape — extend with singleton binding)
    - Modules/Transfers/Internal/Listeners/PairTransferCandidates.php lines 100-200 (partner-query shape — informs the read-side PairLookup queries)
    - Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php (DI + DatabaseManager + raw query builder canonical shape)
    - tests/Contracts/BoundaryArchTest.php (current 96 lines — three new rules to append)
    - tests/Contracts/UserIdColumnArchTest.php (RecursiveIteratorIterator grep-based pattern reference)
    - .planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-PATTERNS.md sections: "Modules/Transfers/Public/Services/PairLookup.php (D-110 promotion — NEW)", "tests/Contracts/BoundaryArchTest.php (MODIFIED — three new invariants)"
  </read_first>
  <behavior>
    - Test 1: `PairLookup::isPaired($txId, $user)` returns `true` for a transaction whose `pair_transaction_id` is non-null AND `user_id` matches; `false` otherwise.
    - Test 2: `PairLookup::partnerId($txId, $user)` returns the partner's int id when paired, `null` when unpaired or cross-user.
    - Test 3: Cross-user access (querying with a different `User`) returns `false` / `null` — NOT a partner id from another user's row.
    - Test 4: `BoundaryArchTest` extended with three new rules: (a) `Modules\\Chains\\Internal` is only used inside `Modules\\Chains`; (b) no resolver writes to `transactions` (D-84); (c) only `CardStatementStateMachine` mutates `card_statements.state` (D-95). The Cache facade carve-out is added to the existing `no Laravel facade usage in module code` rule.
    - Test 5: `HorizonBootsTest::it('connects to Redis on 127.0.0.1:6379')` uses an EXPLICIT skip predicate (issue #9 fix) — `pest()->skip(fn () => !isRedisReachable('127.0.0.1', 6379), 'Redis container required — run docker start diederik-redis')` — NEVER `->skipOnFailure()`. The skip reason surfaces in test output so the precondition failure is visible.
    - Test 6: Horizon config loads (`config('horizon')` returns array).
  </behavior>
  <action>
**Step 1 — Create `Modules/Transfers/Public/Services/PairLookup.php`** per D-110:

```php
<?php

declare(strict_types=1);

namespace Modules\Transfers\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

/**
 * Public read API over `transactions.pair_transaction_id`.
 *
 * Phase 4 introduced `pair_transaction_id` as a self-FK and Phase 4
 * `PairTransferCandidates` writes the symmetric link inside the import
 * transaction. Phase 5 needs a read-side counterpart usable from
 * `Modules/Chains/`: the chain resolver inspects the existing pair
 * before deciding whether a `chain_links.kind='paypal_funding'` row
 * should add another funder leg or stop at the partner row already
 * recorded by Phase 4.
 *
 * Read-only. Never writes `pair_transaction_id` (that's the listener's
 * exclusive job per Phase 4 D-72).
 */
final class PairLookup
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function isPaired(int $txId, User $user): bool
    {
        return $this->db->connection()
            ->table('transactions')
            ->where('id', $txId)
            ->where('user_id', $user->id)
            ->whereNotNull('pair_transaction_id')
            ->exists();
    }

    public function partnerId(int $txId, User $user): ?int
    {
        $row = $this->db->connection()
            ->table('transactions')
            ->where('id', $txId)
            ->where('user_id', $user->id)
            ->first(['pair_transaction_id']);

        if ($row === null || $row->pair_transaction_id === null) {
            return null;
        }

        return self::toInt($row->pair_transaction_id);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
```

**Step 2 — Extend `Modules/Transfers/Providers/TransfersServiceProvider.php`.**

Add `$this->app->singleton(PairLookup::class)` in `register()`. Add `use Modules\Transfers\Public\Services\PairLookup;` to imports.

**Step 3 — `Modules/Transfers/tests/Feature/PairLookupTest.php`.**

Cover the three behaviors (paired-positive, unpaired-negative, cross-user-isolation). At least 5 test cases (paired-true, unpaired-false, partner-id-int, partner-id-null, cross-user-isolation).

**Step 4 — Extend `tests/Contracts/BoundaryArchTest.php` with three new rules.**

Append (do not modify existing rules — only ADD new entries):

```php
arch('Modules\\Chains\\Internal is only used inside Modules\\Chains')
    ->expect('Modules\\Chains\\Internal')
    ->toOnlyBeUsedIn('Modules\\Chains');
```

**Rule (D-84) — no resolver writes transactions:**
```php
it('no Modules/Chains/Internal/Resolvers/ file writes to transactions table (noResolverWritesTransactions)', function (): void {
    $hits = [];
    $resolversDir = base_path('Modules/Chains/Internal/Resolvers');
    if (! is_dir($resolversDir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolversDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match("/Transaction::query|Transaction::where|->table\(['\"]transactions['\"]\)\s*->[^;]*->(update|insert|delete)/", $stripped) === 1) {
            $hits[] = $file->getPathname();
        }
    }
    expect($hits)->toBe([], "Resolver files must not mutate the transactions table. Offenders:\n  ".implode("\n  ", $hits));
});
```

**Rule (D-95) — only CardStatementStateMachine mutates card_statements.state:**
```php
it('only CardStatementStateMachine writes card_statements.state (noOtherCardStatementStateMutator)', function (): void {
    $hits = [];
    $chainsDir = base_path('Modules/Chains');
    if (! is_dir($chainsDir)) {
        return;
    }
    $allowedFile = base_path('Modules/Chains/Internal/CardStatementStateMachine.php');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($chainsDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        if ($path === $allowedFile) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match("/->table\(['\"]card_statements['\"]\)\s*->[^;]*->update\(\s*\[\s*['\"]state['\"]/", $stripped) === 1
            || preg_match('/CardStatement::query\(\)[^;]*->update\(\s*\[\s*[\'"]state[\'"]/', $stripped) === 1) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe([], "Only CardStatementStateMachine may mutate card_statements.state. Offenders:\n  ".implode("\n  ", $hits));
});
```

**Cache facade carve-out** (modify the existing `no Laravel facade usage in module code` rule):

```php
arch('no Laravel facade usage in module code')
    ->expect('Illuminate\\Support\\Facades')
    ->not->toBeUsedIn('Modules')
    ->ignoring([
        // Single permitted facade use: Laravel's queue infrastructure
        // calls uniqueVia() at queue-push time before constructor DI
        // completes, so a constructor-injected Cache repository is not
        // an option. Documented on the class. D-101 / D-103.
        'Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob',
    ]);
```

If `pest-plugin-arch ^4.0` does not support `->ignoring([...])`, fall back to a per-file skip pattern: split the rule into a positive rule that excludes the allow-listed file via a separate `arch()` invocation, OR use a grep-based `it()` test like `noPaypalApiRoute` that grep-strips the carve-out file path.

**Step 5 — Redis Docker smoke + Horizon-boots smoke test with EXPLICIT skip predicate (issue #9 fix).**

Create `tests/Feature/HorizonBootsTest.php`:

```php
<?php

declare(strict_types=1);

use Predis\Client as PredisClient;

/**
 * Helper: try a one-shot socket connect with a 1s timeout.
 * Surfaces the precondition explicitly so the test output names
 * what's missing instead of silently passing as ->skipOnFailure() would.
 */
function isRedisReachable(string $host, int $port): bool
{
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
    if ($socket === false) {
        return false;
    }
    fclose($socket);
    return true;
}

it('connects to Redis on 127.0.0.1:6379', function (): void {
    $client = new PredisClient(['host' => '127.0.0.1', 'port' => 6379]);
    expect($client->ping()->getPayload())->toBe('PONG');
})->skip(
    fn (): bool => !isRedisReachable('127.0.0.1', 6379),
    'Redis container required for this test — run `docker start diederik-redis` or follow the README setup.'
);

it('Horizon service provider boots without errors', function (): void {
    expect(class_exists(\Laravel\Horizon\Horizon::class))->toBeTrue();
    expect(config('horizon'))->toBeArray();
});

it('queue config defaults to redis driver when QUEUE_CONNECTION=redis', function (): void {
    expect(config('queue.default'))->toBe('redis');
})->skip(
    fn (): bool => env('QUEUE_CONNECTION') !== 'redis',
    'QUEUE_CONNECTION=redis required in env to assert default driver.'
);
```

**Why the explicit `skip(fn ...)` over `->skipOnFailure()` (issue #9):**
- `->skipOnFailure()` swallows the test failure silently if the assertion throws ANY exception — including unrelated bugs that have nothing to do with Redis being unreachable. The Wave 0 precondition (Redis container running) becomes invisible in CI logs.
- `->skip(fn () => !isRedisReachable(...), 'Redis container required ...')` evaluates the predicate BEFORE the test body runs. The skip reason appears in `pest` output as "SKIPPED: Redis container required — run docker start diederik-redis", making the missing precondition visible to anyone running tests.
  </action>
  <verify>
    <automated>vendor/bin/pest --filter "PairLookupTest"</automated>
    <automated>vendor/bin/pest --filter "BoundaryArchTest"</automated>
    <automated>grep -q 'PairLookup::class' Modules/Transfers/Providers/TransfersServiceProvider.php</automated>
    <automated>grep -q 'noResolverWritesTransactions\|noOtherCardStatementStateMutator' tests/Contracts/BoundaryArchTest.php</automated>
    <automated>grep -q 'Modules\\\\Chains\\\\Internal\\\\Jobs\\\\ResolveChainLinksJob' tests/Contracts/BoundaryArchTest.php</automated>
    <automated>vendor/bin/pest --filter "HorizonBootsTest"</automated>
    <automated>! grep -q 'skipOnFailure' tests/Feature/HorizonBootsTest.php</automated>
    <automated>grep -q 'isRedisReachable' tests/Feature/HorizonBootsTest.php</automated>
  </verify>
  <acceptance_criteria>
    - `Modules/Transfers/Public/Services/PairLookup.php` exists with `final class PairLookup` + `isPaired(int $txId, User $user): bool` + `partnerId(int $txId, User $user): ?int`.
    - `Modules/Transfers/Providers/TransfersServiceProvider.php` contains `$this->app->singleton(PairLookup::class)` in `register()`.
    - `Modules/Transfers/tests/Feature/PairLookupTest.php` exists with at least 5 test cases.
    - `tests/Contracts/BoundaryArchTest.php` contains the new `arch('Modules\\Chains\\Internal is only used inside Modules\\Chains')` rule.
    - `tests/Contracts/BoundaryArchTest.php` contains the `noResolverWritesTransactions` + `noOtherCardStatementStateMutator` grep-based invariants.
    - The `no Laravel facade usage in module code` rule carries the `ignoring(['Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob'])` allow-list entry.
    - `tests/Feature/HorizonBootsTest.php` uses `->skip(fn () => !isRedisReachable(...), 'Redis container required ...')` (issue #9 fix) and does NOT contain the string `skipOnFailure`.
    - `HorizonBootsTest` passes (`vendor/bin/pest --filter "HorizonBootsTest"`); the Redis-ping test either passes (container running) or surfaces a visible skip reason.
    - Larastan level 10 strict passes with zero NEW errors.
    - Pint formatting clean: `composer format:check` exits 0.
  </acceptance_criteria>
  <done>
    PairLookup Public service exists, is DI-bound, is covered by tests including cross-user isolation. BoundaryArchTest extended with three new rules (Chains/Internal scope, D-84 resolver invariant, D-95 state-machine invariant) AND the Cache facade carve-out. HorizonBootsTest uses an explicit skip predicate (issue #9 fix).
  </done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 4: Verify Wave 0 module + fixture half is operational</name>
  <what-built>
    Modules/Chains module skeleton, synthesised cross-source fixture trio (clean/overpaid/underpaid), PairLookup Public promotion, three new BoundaryArchTest invariants, HorizonBootsTest with explicit skip predicate (issue #9 fix).
  </what-built>
  <how-to-verify>
    1. Run the full Wave 0 quick-filter test suite:
       ```bash
       vendor/bin/pest --filter "Chains|PairLookup|BoundaryArchTest|HorizonBoots|SmokeTest|FixtureParseSmokeTest"
       ```
       Expected: all green. The Redis-ping test inside HorizonBootsTest either passes (container running per 05-01) or surfaces a visible skip reason "Redis container required — run `docker start diederik-redis` or follow the README setup."

    2. Verify the synthesised fixture is reconcilable:
       ```bash
       # ICS PDF transaction count
       php -r 'echo `pdftotext Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf - | grep -c "Af"`;'
       # Expected: 23

       # ASN bulk-iDEAL amount (clean variant)
       xmllint --xpath "string(//*[local-name()='Ntry'][1]/*[local-name()='Amt'])" Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml
       # Expected: 847.32

       # Overlay variants reconcile to the documented deltas
       jq '.bulk_settle_amount_minor' Modules/Chains/tests/fixtures/scenario-1/scenario-1-overpaid.json
       # Expected: 84885
       jq '.bulk_settle_amount_minor' Modules/Chains/tests/fixtures/scenario-1/scenario-1-underpaid.json
       # Expected: 84514
       ```

    3. Verify BoundaryArchTest invariants:
       ```bash
       grep 'noResolverWritesTransactions\|noOtherCardStatementStateMutator\|ResolveChainLinksJob' tests/Contracts/BoundaryArchTest.php
       ```
       Expected: at least 3 distinct lines naming the new invariants + the carve-out FQN.

    4. Verify HorizonBootsTest does NOT use skipOnFailure (issue #9):
       ```bash
       grep skipOnFailure tests/Feature/HorizonBootsTest.php
       ```
       Expected: NO output (the string must not appear).

       ```bash
       grep 'isRedisReachable\|Redis container required' tests/Feature/HorizonBootsTest.php
       ```
       Expected: matches both — the explicit predicate + skip-reason string.
  </how-to-verify>
  <resume-signal>
    Type "approved" if all 4 checks pass. Type "fail: {description}" with the failing check if any verification fails so the planner can revise.
  </resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Composer ←→ Packagist | This plan does NOT pull new packages (those landed in 05-01). It extends autoload-dev psr-4 only. |
| Fixture files ←→ tests | Synthesised fixtures live in committed test data; tests load them via file_get_contents. |
| `Modules/Chains/` ←→ rest of project | New bounded module — BoundaryArchTest invariants enforce its API surface. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-01b-01 | Tampering | Synthesised fixture files modified by accident or malicious commit | mitigate | Fixtures are idempotent — `scripts/synthesise_phase5_scenario.php` re-runs to byte-identical output. CI can re-run the script and `git diff --exit-code` against `Modules/Chains/tests/fixtures/scenario-1/` (deferred to a future hook). For now, the smoke test (`FixtureParseSmokeTest`) ensures fixtures are at least PARSEABLE — corruption would surface as a smoke-test failure. |
| T-05-01b-02 | Elevation of Privilege | BoundaryArchTest carve-out for `Cache::driver('redis')` widens the facade allow-list | mitigate | Carve-out is per-file (single FQN string `Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob`); BoundaryArchTest fails if any OTHER file in `Modules/` imports `Illuminate\Support\Facades\Cache`. Constructor-DI is the only path everywhere else. Wave 2 covers the `ResolveChainLinksJob` implementation with explicit class-level docblock noting the carve-out. |
| T-05-01b-03 | Information Disclosure | PairLookup leaks one user's partner-id to another user | mitigate | Every PairLookup query filters on `->where('user_id', $user->id)` first. PairLookupTest covers the cross-user case. ASVS V4. |
| T-05-01b-04 | Tampering | A future code change uses `->skipOnFailure()` on HorizonBootsTest, hiding the Redis precondition again | mitigate | Verify gate `grep skipOnFailure tests/Feature/HorizonBootsTest.php` returns NO output. If ever a maintainer reintroduces it, the gate fails CI. (Issue #9 fix lock.) |
</threat_model>

<verification>
- Module skeleton compiles + autoload regen clean.
- All Wave 0 (module half) tests green: SmokeTest, FixtureParseSmokeTest, PairLookupTest (≥5 cases), BoundaryArchTest (all rules including 3 new ones), HorizonBootsTest.
- Larastan level 10 strict: zero NEW errors above the baseline.
- Laravel Pint clean: `composer format:check` exits 0.
- HorizonBootsTest does NOT contain the string `skipOnFailure` (issue #9 fix lock).
- All 6 synthesised fixture files exist under `Modules/Chains/tests/fixtures/scenario-1/`.
- BoundaryArchTest `noResolverWritesTransactions` + `noOtherCardStatementStateMutator` + `Modules\\Chains\\Internal` scope rules present.
</verification>

<success_criteria>
05-01b complete when:
- [ ] Module skeleton at `Modules/Chains/` exists (composer.json, ServiceProvider, tests/Pest.php, tests/TestCase.php, placeholder directories, SmokeTest).
- [ ] `ChainsServiceProvider` registered in `bootstrap/providers.php`.
- [ ] `composer.json` `autoload-dev.psr-4` extended for `Modules\\Chains\\Tests\\`.
- [ ] Synthesised fixture trio committed under `Modules/Chains/tests/fixtures/scenario-1/` (6 files).
- [ ] `scripts/synthesise_phase5_scenario.php` committed and idempotent.
- [ ] `FixtureParseSmokeTest` green — fixtures parse via existing Phase 2/3/4 adapters.
- [ ] `Modules/Transfers/Public/Services/PairLookup` exists + singleton-bound in TransfersServiceProvider + smoke-tested (PairLookupTest ≥5 cases).
- [ ] `tests/Contracts/BoundaryArchTest.php` extended with `Modules\\Chains\\Internal` scope rule + D-84 grep rule + D-95 grep rule + Cache facade carve-out.
- [ ] `tests/Feature/HorizonBootsTest.php` uses EXPLICIT skip predicate (issue #9 fix); does NOT contain `skipOnFailure`.
- [ ] `phpunit.xml` lists three new testsuites for Chains.
- [ ] Larastan level 10 strict + Pint format clean.
- [ ] Operator confirms the module + fixture half is operational (Task 4 checkpoint approved).
</success_criteria>

<output>
After completion, create `.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-01b-SUMMARY.md` documenting: fixture file MD5 hashes, BoundaryArchTest delta (new rules + carve-out), the explicit-skip-predicate result for HorizonBootsTest, and any deviations.
</output>
