# ADR 0003 — Hippocratic License 3.0

- **Status:** Accepted
- **Date:** 2026-05-27
- **Graduated from:** Phase 17, decision D-33

## Context

beatrax is a local-only personal-finance dashboard. It reads bank
statements, credit-card PDFs, PayPal exports, and email receipts. It
resolves funding chains across accounts. That class of code earns trust
by being readable — by shipping its full source so the people who run
it can audit what it does on their own machine.

Closing the source would have been the simpler legal choice. It would
also have been the wrong product choice for a privacy-first tool whose
core promise — "nothing leaves your machine" — is only credible when
the user can verify it themselves.

Three requirements had to hold at once:

1. **The source must be visible.** The privacy story collapses if the
   user has to take the maintainer's word for it.
2. **The source must be redistributable in some form.** Users need to
   fork their own copy, pin a specific version, ship a patched build to
   a partner. A fully closed license blocks the community contribution
   that small open-development projects depend on.
3. **The license should express that the code is not a tool for harm.**
   Finance products show up in surveillance and rights-abuse contexts.
   Naming that risk explicitly is a low-cost way to set the tone.

OSI-approved permissive licenses (MIT, BSD, Apache-2.0) satisfy
requirements 1 and 2 but cannot satisfy 3 — the Open Source Definition
forbids restrictions on fields of endeavour. OSI-approved copyleft
licenses (GPL, AGPL) satisfy 1 and 2 but turn distribution into a viral
copyleft event for anyone who builds on the code; that is the wrong
trade-off for a single-user dashboard. Closed-source licenses fail
requirement 1.

The Hippocratic License 3.0 (firstdonoharm.dev) satisfies all three.

## Decision

beatrax ships under the [Hippocratic License 3.0](https://firstdonoharm.dev/),
specifically the unmodified `HL3-FULL` variant covering the full
ethical-use clause set. The license text lives at the repo root in
`LICENSE`; the human-readable rationale lives at
[`legal/license-rationale.md`](../legal/license-rationale.md).

## Consequences

- **Source-available, not open-source.** The Hippocratic License 3.0 is
  not OSI-approved. Procurement processes, downstream relicensing
  workflows, and "is this open-source?" compliance checks will return
  "no, it is source-available". This is the explicit trade.
- **Not bundleable as a permissively-licensed dependency.** Other
  projects cannot pull beatrax in as a library under MIT / Apache
  umbrella terms. beatrax is a finished product, not a building block;
  this constraint matches the intent.
- **License binds the licensee to the ethical-use clauses.** Modifications
  and redistributions inherit the same terms. The full clause set,
  drawn from international human-rights frameworks, is reproduced
  verbatim in `LICENSE`.
- **Documentation surface.** The README links to the license; `NOTICE.md`
  links to the license; the in-app About surface links to
  [`legal/license-rationale.md`](../legal/license-rationale.md). All
  three surfaces use the exact phrasing "source-available under the
  Hippocratic License 3.0" rather than "open-source", so readers form an
  accurate expectation.

## Alternatives considered

- **MIT / Apache-2.0** — satisfies visibility and redistribution but
  cannot carry the ethical-use clause. Rejected.
- **AGPL-3.0** — satisfies visibility and a stronger redistribution
  requirement, but the viral copyleft is the wrong trade for a
  single-user dashboard nobody else will bundle as a dependency anyway.
  Rejected.
- **Closed-source with binary distribution only** — fails the visibility
  requirement. Rejected.
- **Custom "ethical source" license drafted from scratch** — too much
  legal risk for a one-person project. Hippocratic 3.0 is the
  off-the-shelf answer to exactly this design space. Accepted.

## Related

- [`legal/license-rationale.md`](../legal/license-rationale.md) — the
  long-form public explanation, linked from README and `NOTICE.md`.
- [ADR 0004 — Local-only hosting](0004-local-only-hosting.md) — the
  privacy posture this license codifies.
