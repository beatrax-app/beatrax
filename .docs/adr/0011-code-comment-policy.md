# ADR 0011 — Code comment policy: readable code, architecture in `.docs`

**Status:** Accepted

## Context

The codebase had drifted toward comment-heavy source: single-line notes restating what the
next line does, block `/* */` prose duplicating what belongs in `.docs`, workflow
provenance (`Phase 5`, `D-95`, `LOCK-04`) scattered through docblocks, and PHPDoc summary
paragraphs that a reader must reconcile against both the code and the architecture docs.

This works against two positions the project already holds:

- Code should be readable on its own; naming and structure carry intent, not prose.
- Architecture lives in Markdown under `.docs/` (see [ADR 0001](0001-modular-architecture.md)),
  linked from code — not re-explained inline where it rots out of sync.

A convention was needed that (a) is enforceable in CI so it does not depend on reviewer
vigilance, (b) draws a clean line between machine directives that must be kept and prose
that must go, and (c) is portable — copyable into other projects that share the `.docs/`
tree and Pest, without carrying beatrax-specific assumptions.

The alternatives considered were: leaving it to reviewer judgment (rejected — it had
already failed); a Pint/formatting rule (rejected — Pint governs style, not comment
semantics, and cannot express "prose belongs in `.docs`"); and a fully judgment-based doc
with no test (rejected — unenforceable, so it would drift).

## Decision

Adopt the convention documented in
[`.docs/conventions/code-comments.md`](../conventions/code-comments.md), enforced at two
levels:

- **Mechanical rules**, guarded by a Pest test (`tests/Contracts/CommentPolicyArchTest.php`)
  that walks backend production PHP and asserts on comment tokens via `token_get_all()`:
  no lone single-line `//`; inline `//` blocks are 2–4 lines; no informative `/* */`;
  docblocks are `@`-tag only with no descriptive prose; no `TODO`/ticket/phase-provenance
  tokens; every `@link` `.md` target resolves to a real `.docs` file.
- **Judgment rules**, binding on every contributor (human or AI) but not machine-checkable:
  prefer self-documenting code; comment only genuinely complex *why*; architecture goes in
  `.docs`; nothing is deferred in a comment.

Documentation links use `@link` for `.md` paths and `@see` for code symbols. The scope is
`Modules/**` and `app/**` PHP, excluding `tests/` and `Database/Migrations/`. Frontend and
Blade are out of scope for this iteration.

## Consequences

- **The Pest test is the binding invariant.** New backend code that violates a mechanical
  rule fails CI. The judgment rules remain a review responsibility.
- **A one-time sweep is required.** Existing violations — provenance tokens, prose
  docblocks, lone one-liners — are cleaned across every backend file before the test is
  activated. The sweep is manual per-file (not a blind find-and-replace): every file is
  read and corrected so no genuine *why* is lost. This is scheduled as a dedicated phase
  in the current milestone alongside landing the test.
- **PHPDoc becomes structural, not narrative.** Class purpose is carried by the class name
  plus an `@link` to `.docs`; anything that was a description paragraph moves into the
  linked page. This deepens the reliance on `.docs/` being accurate and current.
- **Portability is a first-class goal.** The convention file and its reference test are the
  unit of reuse; they carry no beatrax-specific assumptions beyond the scope roots, and can
  be dropped into any project on the same `.docs/` + Pest stack.
- **Reinforces [ADR 0001](0001-modular-architecture.md) and [ADR 0002](0002-di-only-rule.md).**
  Like the module-boundary and DI-only rules, this is a code-shape invariant enforced by an
  arch-style test rather than by convention alone.
