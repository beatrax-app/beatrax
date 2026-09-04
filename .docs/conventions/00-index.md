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
drifted apart once, and identifiers went on living in test names because only
comments were being read. Standards names share the shape an identifier has, so `SHA-256`
and its neighbours sit in a named allow-list in that test — extend the list
rather than working around the pattern. A regex character class that merely
reads as an identifier is exempted as an exact literal, never by loosening the
pattern.

It holds for **Blade comments** too — and for *every* comment in a Blade file,
not just the `{{-- --}}` form. A `{{-- --}}` block is inline HTML to the PHP
tokeniser, so the token-based passes cannot see one and a separate pass lifts
them out of the raw source; the `//` and `/* */` comments inside an `@php`,
`<?php` or `<script>` island are invisible for exactly the same reason, and
gating only the first form left identifiers sitting untouched in the other half
of the same files. JS written into an Alpine attribute (`x-data`, `x-on:…`) is *not*
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
- [Translations awaiting a native reader](translations-awaiting-a-native-reader.md)
  — the `i18n-review:` marker, and the standing work-list of strings carrying it
- [Copy that follows the reader](../features/notifications/reader-language-copy.md)
  — the seam a stored line keeps its key through, for a column a screen reads
  back long after the language that wrote it
- [A translated line has a call site](a-translated-line-has-a-call-site.md) — the
  four ways a key is assembled, and why parity cannot see a line nothing renders
- [A call site names a key that resolves](a-call-site-names-a-key-that-resolves.md)
  — the other direction: a key no locale declares renders as itself, and the four
  shapes that look broken and are not
- [A mark that is a picture carries U+FE0F](emoji-presentation-selector.md) — the
  three characters the two phone engines drew differently, and the one they stay
  bare for
- [An icon-only action says its verb on touch](an-icon-only-action-says-its-verb-on-touch.md)
  — why `title` is not a label on a phone, why the hold is Android's own
  gesture, and the event order that made a hold archive the row it was naming
- [Help a reader can open](help-a-reader-can-open.md) — the tip beside a label
  the screen cannot teach, why its mark is a glyph where an action's is an emoji,
  and the `@link` that keeps the copy tied to the page it came from
- [A public Livewire method is a public endpoint](a-public-livewire-method-is-a-public-endpoint.md)
  — why an unreachable action is two defects rather than one, what the guard
  counts as a caller, and the six callers a grep cannot see
- [Which actions ask before they act](which-actions-ask-before-they-act.md) — the
  order of preference (reversible over a prompt), the three shapes a question
  takes, and the judgment behind every action left bare
- [Analyser rules enforced locally](analyser-rules-enforced-locally.md) — the
  three hosted-analysis rules that now fail on the commit rather than on the
  dashboard, why each one reports far less than its name suggests, and how each
  guard was checked against the published figures before it was written
- [A controller hands the work to an action](a-controller-hands-the-work-to-an-action.md)
  — the four things a controller is measured on, and the five kinds of code that
  look like violations, are not, and would be made worse by moving
- [Architecture](../architecture/00-index.md) — the system's shape
- [40-quality/code-standards.md](https://github.com/beatrax-app/spec/blob/main/40-quality/code-standards.md)
- [50-governance/ai-contributors.md](https://github.com/beatrax-app/spec/blob/main/50-governance/ai-contributors.md) — the judgment rules bind AI contributions identically
