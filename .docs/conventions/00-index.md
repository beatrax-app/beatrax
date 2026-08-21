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

That holds for **Pest test names** too, and the arch test scans the `it()`,
`test()` and `describe()` literals as well as the comments. A test name is read
at a failure, where what broke is the useful thing to know and which requirement
row it traces to is not. Names and comments read the *same* pattern — they
drifted apart once, and 19 `Wave`/`issue #` names survived a 420-name sweep
because of it. Standards names share the shape an identifier has, so `SHA-256`
and its neighbours sit in a named allow-list in that test — extend the list
rather than working around the pattern. A regex character class that merely
reads as an identifier is exempted as an exact literal, never by loosening the
pattern.

It holds for **Blade comments** too — and for *every* comment in a Blade file,
not just the `{{-- --}}` form. A `{{-- --}}` block is inline HTML to the PHP
tokeniser, so the token-based passes cannot see one and a separate pass lifts
them out of the raw source; the `//` and `/* */` comments inside an `@php`,
`<?php` or `<script>` island are invisible for exactly the same reason, and
gating only the first form left 26 identifiers sitting in the other half of the
same files. JS written into an Alpine attribute (`x-data`, `x-on:…`) is *not*
scanned — an attribute value has nowhere to put a comment. A `UI-SPEC
§`-section reference is a pointer into a living document rather than a
requirement identifier, and stays. The
identifier ban also covers `config/`, `routes/` and `scripts/`, which the style
rules do not — an identifier sat unnoticed in `config/nativephp.php` for exactly
that reason, and `M3`/`M4` would forbid the header block that is a build hook's
only documentation.

It holds for **stylesheets, scripts and config files** too — `resources/css/`,
`resources/js/`, `build/`, `vite.config.js`, the `phpstan*.neon` files, both
`phpunit.xml` files and everything under `.github/`. None of these reach
`token_get_all`, so each gets a hand-written scanner, and each scanner is
written to be *unable to lie in either direction*: it must not invent an
offender, and it must not go quietly blind.

The naive matchers are the trap. `//` also appears inside `'https://…'` and in
the middle of the regex literal `/\//g`; `#` also delimits every `'#…#'` PHPStan
message in `phpstan.neon` and opens every URL fragment. So the JS/CSS scanner
walks the source skipping strings, template literals and regex bodies, and the
`#` scanner requires both *outside a quoted run* and *after a line start or
whitespace*. A quoted run or a regex that reaches the end of its line is a
misread rather than a literal, and the scanner backs out and re-reads the
character as ordinary. Two tests pin the cases that would make either scanner
lie, and each rule asserts its file list is non-empty so an empty scan cannot
pass for a clean one.

## Related

- [Copy that carries a count](counted-nouns-in-copy.md) — how a number and a
  noun are written together across 26 locales
- [Architecture](../architecture/00-index.md) — the system's shape
- [40-quality/code-standards.md](https://github.com/beatrax-app/spec/blob/main/40-quality/code-standards.md)
- [50-governance/ai-contributors.md](https://github.com/beatrax-app/spec/blob/main/50-governance/ai-contributors.md) — the judgment rules bind AI contributions identically
