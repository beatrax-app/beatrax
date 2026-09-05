# AGENTS.md — Beatrax

Guidance for any AI agent (Claude Code, Cursor, Codex, Aider, …) working in this
repository.

> **Common rules for every Beatrax repository are canonical in the spec:**
> [50-governance/ai-contributors.md](https://github.com/beatrax-app/spec/blob/main/50-governance/ai-contributors.md).
> Read them. This file is the `beatrax`-specific header only.

## What this repo is

The product: a Laravel 13 / PHP 8.5 application organised into **35 modules**,
shipped as a desktop bundle for macOS, Windows, and Linux plus a mobile client,
and runnable self-hosted. Livewire single-file components over Tailwind v4;
SQLite in write-ahead journal mode.

The spec page is
[`30-repos/beatrax.md`](https://github.com/beatrax-app/spec/blob/main/30-repos/beatrax.md).

## The one rule you cannot break here

**A module's `Internal\` namespace is private.** The cross-module surface is
`Public\` (contracts, DTOs, events, services) **plus** `Models\` — Eloquent models
are a deliberate shared read-seam other modules may use directly. Nothing else
crosses.

Enforced twice, so it cannot be argued around: `App\PhpStan\Rules\BoundaryRule`
at static-analysis time, and `pinnedCrossModuleInternalImports` in
`tests/Contracts/BoundaryArchTest.php`, which pins every crossing that exists in
production and in tests. A Blade view mounting a neighbour's Livewire component
by alias names no class, so no import scan can see it; `pinnedCrossModuleLivewireMounts`
in the same file pins those. If you find yourself importing another module's
`Internal\`, the answer is a new `Public\` contract, not an exception.

The map is [`.docs/architecture/module-boundaries.md`](.docs/architecture/module-boundaries.md);
the contract is
[`20-architecture/contracts/module-boundary.md`](https://github.com/beatrax-app/spec/blob/main/20-architecture/contracts/module-boundary.md).

## The second rule, which is easy to break by accident

**No runtime extension outside the bundled set.** In particular the removed mail
extension is not used anywhere, which is the contractual reason mail access is
**provider-API-only** (Gmail API, Microsoft Graph) — never IMAP
([A4](https://github.com/beatrax-app/spec/blob/main/10-functional/features/a-ingestion/a4-email-scanning.md),
[platform-matrix](https://github.com/beatrax-app/spec/blob/main/20-architecture/platform-matrix.md)).
An IMAP library is not a shortcut here; it is a specification violation.

## Where to start

1. [`30-repos/beatrax.md`](https://github.com/beatrax-app/spec/blob/main/30-repos/beatrax.md) — what this repo owns versus what the spec owns.
2. [`20-architecture/component-model.md`](https://github.com/beatrax-app/spec/blob/main/20-architecture/component-model.md) — the module map.
3. The feature you are implementing, under [`10-functional/features/`](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).
4. The module's implementation map in [`.docs/features/<module>/`](.docs/features/) — where the code actually lives.
5. **Find the requirement your change serves before editing. Cite it.**

## Code standards (enforced)

- **The gate is four checks, all blocking:** `vendor/bin/pint --test`,
  `vendor/bin/phpstan analyse` at **level 10 in strict mode**,
  `vendor/bin/pest` — unit, feature, contract, and architecture in one run —
  and `composer analyse:deps`, which fails when code imports a namespace no
  direct dependency declares. A package reachable only through another
  package's requirements disappears the day that package drops it, so an
  undeclared import is a break scheduled for an unrelated `composer update`.
- Comments explain *why*, never *what*, and the bar is high: if the code says it,
  the comment does not need to. An inline `//` block is **2 to 4 lines**: a lone
  one-line `//` comment is refused outright (M1), because a thought that fits on
  one line is one the code should carry itself — delete it, or let a rename, an
  extracted method or a named constant say it. **Never pad a one-liner to two
  lines to clear M1** — that manufactures the noise the rule exists to stop.
  No prose in a docblock: tag-only (M4), and no `/* */` blocks (M3).
- These four rules are enforced twice: `tests/Contracts/CommentPolicyArchTest.php`
  is the authority, and `.claude/hooks/comment-policy.php` runs the same checks on
  every edit so a violation is reported the moment it is written rather than at
  the gate. Both cover `Modules/` and `app/`, never tests or migrations.
- **No requirement identifiers in comments** (`GOV-R6`). They go in the commit
  trailer and the PR body, which is where the gate reads them.
- An `@link` into `.docs/` is for a target a reader could not have guessed — a
  specific page or section. Do NOT add one pointing at the module's own
  `architecture.md`: the file path already says which module this is, and a
  thousand copies of that line said nothing. **A link that IS present is verified
  to resolve (`M6`) — a broken one fails the build.**
- Material too involved to sit in four lines belongs in `.docs/` as its own page,
  with an `@link` to it. That is the case an `@link` exists for.
- Repo-specific technical detail goes in [`.docs/`](.docs/00-index.md). Product
  decisions and requirements go in the spec. Where the two disagree, the spec
  wins and the page here is corrected (`REPO-R36`).

## Before you open a PR

- All four gate checks pass locally (`composer format:check && composer analyse
  && composer analyse:deps && composer test`).
- Your change cites a spec identifier in a commit `Spec:` trailer **and** in the
  PR body — the gate reads both. Routine maintenance cites `GOV-R12`.
- Behaviour change? **The spec PR merged first**
  ([change-lifecycle](https://github.com/beatrax-app/spec/blob/main/50-governance/change-lifecycle.md)).
- The [definition of done](https://github.com/beatrax-app/spec/blob/main/40-quality/definition-of-done.md) is met.

A pull request is judged on whether it satisfies the requirement it cites,
passes the gates, and is code somebody can maintain. Review is there for a
reason.

## Commits

Conventional subjects, **signed off** matching your author identity, signed, and
carrying a `Spec:` trailer:

```text
feat(ledger): pair a settlement against its originating charge

Spec: B5-R13
Signed-off-by: Your Name <you@example.com>
```

Write the subject for the person who will read the release notes: the notes are
assembled from the commits, so the subject *is* the entry.
