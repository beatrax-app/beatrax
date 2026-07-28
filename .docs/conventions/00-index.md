# Conventions

Day-to-day rules for how code is written here. The `architecture/` tree describes
the *shape* of the system; this subtree holds the rules that apply while writing
any file in it.

## The comment policy is canonical in the spec

The rule text — the mechanical rules `M1`–`M6`, the judgment rules `J1`–`J5`, the
directive allow-list, and the enforcement model — lives in the specification and
is the single source of truth:

**[40-quality/code-comments.md](https://github.com/beatrax-app/spec/blob/main/40-quality/code-comments.md)**
· decided in [ADR-0011](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0011-code-comment-policy.md)

This page does not restate it. A second copy of a rule is a second thing to keep
current, and the copy is the one that goes stale.

## What that means in this repository

| Concern | Where it lives |
|---|---|
| The rules themselves | The spec page above |
| The mechanical enforcement | `tests/Contracts/CommentPolicyArchTest.php` |
| The banned-token pattern and directive allow-list | The same test — the two tuned knobs |
| What a class *is* | Its name plus its `@link` into this tree |

The load-bearing consequence, stated in the spec and worth repeating where
contributors will hit it: **class purpose moves into the documentation.** A class
carries a documentation link instead of a prose summary, so a `.docs` page that
source links to is not optional reading — deleting or renaming one breaks `M6`
and fails the build.

## Linking from code

Two tags, never mixed:

- **`@link`** — a path into this tree, relative to the source file. Verified to
  resolve by `M6`.
- **`@see`** — a code symbol. Never a documentation path.

**Never a requirement identifier in a comment**
([GOV-R6](https://github.com/beatrax-app/spec/blob/main/50-governance/canonical-spec.md#never-in-code-comments)).
Identifiers belong in the commit trailer and the pull-request body, where the
governance gate reads them.

## Related

- [Architecture](../architecture/00-index.md) — the system's shape
- [40-quality/code-standards.md](https://github.com/beatrax-app/spec/blob/main/40-quality/code-standards.md)
- [50-governance/ai-contributors.md](https://github.com/beatrax-app/spec/blob/main/50-governance/ai-contributors.md) — the judgment rules bind AI contributions identically
