---
id: SEED-001
status: dormant
planted: 2026-05-17
planted_during: v1.0 / post-phase-8
trigger_when: when all originally planned milestone work is complete and ready to ship beyond the developer's own machine
scope: large
---

# SEED-001: Final milestone — public release readiness, desktop packaging, CI/CD, and deep Modules review

## Why This Matters

Diederik is a local-only personal finance dashboard, but the user has signalled intent to share it once the original product is proven (partner-ready note already on file). Before the project can move from "runs on my Mac via Herd" to "anyone can install and run it", a final wrap-up milestone is needed to:

1. **Desktop packaging** — investigate wrapping the Laravel + Livewire app as a desktop application (NativePHP, Tauri, Electron + PHP runtime, or similar) so a non-developer can install it like a normal Mac/Windows app, with the SQLite store living in the user's profile directory rather than `~/Herd/`.
2. **GitHub auto build/release CI** — set up workflow(s) that build platform-specific installers on tag pushes, sign them where applicable, attach them to a GitHub release, and run the existing Larastan + Pint + Pest gates per PR.
3. **Public release readiness** — README rewrite for a public audience, contributor docs, license, security policy, screenshots, and any redaction work needed to remove project-internal references (`.planning/`, `D-NNN` codes, etc.) from anything ship-facing.
4. **Deep Modules code review** — a milestone-spanning pass over every bounded module (`Modules/Ledger`, `Modules/Categorization`, `Modules/Receipts`, `Modules/EmailScan`, `Modules/Chains`, `Modules/Recurring`, …) covering: cross-module boundary hygiene, DI compliance, dead code, edge cases, performance smells, and consistency between modules. Larger surface than per-phase code review — comparable to a pre-release security/quality audit.

This is the natural last milestone because each item only makes sense once the original feature scope is complete: there is no point packaging an unfinished app, and a deep review across all modules is wasted effort if more modules are still landing.

## When to Surface

**Trigger:** Surface during `/gsd:new-milestone` when the ROADMAP shows all originally planned phases (Phase 1 through the last subscription/forecasting/dashboard phase) are complete and no further new-feature milestones are queued. Also surface explicitly if the user asks for a "public release", "ship publicly", "desktop app", "installer", or "open source the project".

## Scope Estimate

**Large** — a full milestone in its own right. Likely 4–6 phases:

1. Desktop packaging spike + decision (NativePHP vs. wrapper vs. browser-only public release with self-host docs)
2. CI/CD: PR gates + tag-triggered release pipeline + signing
3. Cross-module deep review + remediation phase
4. README / docs / license / security policy / screenshots
5. Redaction pass (purge any GSD-planning leakage that survived per-phase reviews)
6. Beta-test cycle with at least one external user (the partner the user already mentioned) before opening the repo to the world

## Breadcrumbs

- `CLAUDE.md` — current "Constraints" section lists `Hosting: Local only (localhost)` as a privacy requirement; revisit whether public-release means staying local-only-but-installable, or whether a hosted variant becomes acceptable.
- `.planning/PROJECT.md` — `### Out of Scope` lists "Cloud hosting / multi-device sync" with the note "Revisit only after v1 proves itself"; this seed is the moment that revisit happens.
- `.planning/REQUIREMENTS.md` — single-user-but-partner-ready (PRIV-* / multi-user readiness) lines feed directly into the desktop-packaging UX decisions.
- `.github/` — does not exist yet; CI workflow scaffolding lands here.
- `Modules/*/` — every bounded module is in scope for the deep review.
- User memory: `feedback_codebase_gsd_agnostic.md` — the redaction pass enforces this rule at release time across every doc and code comment.

## Notes

Captured via `/gsd-capture` on 2026-05-17 immediately after Phase 8 completion. Phase 8 is the most recent bounded module (`Modules/Recurring/`) — its addition makes the "deep Modules review" sub-item materially larger, which is a good reason to wait until the original scope is done before kicking this off.

Status remains `dormant` until the trigger fires. Enrich later via `/gsd:capture --seed --enrich SEED-001` if scope or trigger needs to be tightened.
