# Code comments

Code should be readable on its own. Architecture belongs in `.docs/`. Comments are the
exception, not the norm — reserved for the few places where genuinely non-obvious code
needs a *why* that the code itself cannot carry.

This file is the single source of truth for what a backend comment may be. It is written
to be copied verbatim into another project's `.docs/conventions/` tree alongside its
[Pest enforcement test](#enforcement).

## Philosophy

1. **Readable code first.** If a comment could be deleted by renaming a variable,
   extracting a method, or introducing a well-named constant — do that instead.
2. **Architecture lives in `.docs/`.** System shape, data flow, cross-module contracts,
   and "how the pieces fit" are documented in Markdown and linked from the class, never
   re-explained inline.
3. **Comments explain *why*, never *what*.** A comment that restates what the next line
   does is noise. A comment that captures a non-obvious reason, constraint, or trap earns
   its place — and if it does, it is never a throwaway one-liner.
4. **Production-ready always.** Code that ships is finished. There are no deferral notes,
   no tickets, no "come back to this." If work remains, the work is not done.

## Scope

Applies to **backend production PHP**:

- `Modules/**/*.php`
- `app/**/*.php`

Explicitly **excluded**:

- `**/tests/**` — test files may carry explanatory rationale freely.
- `**/Database/Migrations/**` — framework-shaped scaffolding.
- Blade views (`*.blade.php`) and any frontend asset — out of scope for now.

## The two layers

This convention is enforced at two levels. Keep them distinct — conflating them makes the
test either toothless or tyrannical.

- **Mechanical (rules `M*`).** Comment *shape*. Deterministic, greppable, enforced by the
  Pest test. A regex/tokenizer can prove these.
- **Judgment (rules `J*`).** Comment *worth*. Whether code is complex enough to deserve a
  comment at all. Only a human reviewer or an AI assistant can judge these; the test
  cannot. They are binding on every contributor even though no test guards them.

## Mechanical rules

| # | Rule |
|---|---|
| **M1** | No lone single-line `//` comment. An informative `//` must be part of a contiguous block of **2–4** lines. A one-line note means the code should say it instead — delete it or, if the *why* is real, expand it into a proper block. |
| **M2** | A contiguous `//` block is **2 lines minimum, 4 lines maximum**. Anything that needs more than 4 lines of prose belongs in `.docs/`, linked via `@link`. |
| **M3** | No informative `/* … */` block comments. Only PHPDoc (`/** … */`) may use the block form. |
| **M4** | Docblocks (`/** … */`) are **`@`-tag only**. No descriptive prose: no summary paragraph before the first tag, and no docblock whose content is prose with zero tags. Multi-line continuations of a tag (e.g. a long `@param` description) are fine. The class's purpose is carried by its name plus an `@link` to `.docs`, not a paragraph. |
| **M5** | No deferral or provenance tokens anywhere in a comment: `TODO`, `FIXME`, `HACK`, `XXX`, `@todo`, ticket keys, and project-workflow references (`Phase 5`, `D-95`, `LOCK-04`, `WR-04`, and similar). |
| **M6** | Every `@link` that names a `.md` file must resolve to a real file under `.docs/`. Broken doc links fail the build. |

### The directive allow-list

A small set of single-line `//` and `/** … */` comments are **not** informative comments —
they are machine directives that tooling reads. These are exempt from M1–M4 and must be
retained:

- `// @phpstan-ignore-next-line`, `// @phpstan-ignore …`
- `/** @var Foo $bar */`, `/** @phpstan-var … */` and other PHPStan/Larastan inline types
- `// @codeCoverageIgnore`, `// @codeCoverageIgnoreStart`, `// @codeCoverageIgnoreEnd`
- `// phpcs:*`, `// @psalm-*` (if ever introduced)

The allow-list is deliberately small and precise. Adding to it is a reviewed change, not a
convenience.

## Judgment rules

| # | Rule |
|---|---|
| **J1** | Prefer self-documenting code. Exhaust rename / extract / named-constant before writing any comment. |
| **J2** | Write an inline `//` block only when the code is genuinely complex or the *why* is non-obvious (a constraint, an ordering trap, a defended-against edge case). Never to narrate *what* the code does. |
| **J3** | Architecture, data flow, and cross-module contracts go in `.docs/` (`architecture/` or `features/`), never inline. |
| **J4** | When a class has a documented home in `.docs/`, link it from the class with `@link`. The class needs no prose description — its name and that link are the documentation. |
| **J5** | Nothing is deferred in a comment. If it isn't done, it isn't production-ready — finish it or cut it. |

## Linking to `.docs`

Two tags, two purposes — do not mix them:

- **`@link`** — points at a **documentation path**. Relative to the source file, targeting
  a `.md` file under `.docs/`. Greppable and verified to exist by M6.
  ```php
  /**
   * @link ../../../.docs/architecture/chain-resolution.md
   */
  ```
- **`@see`** — points at a **code symbol** (class, method, constant), in the conventional
  PHPDoc form. Never a `.md` path.
  ```php
  /**
   * @see CardStatementStateMachine::transition()
   */
  ```

## PHPDoc shape

A valid docblock under M4 looks like this — tags only, no preamble:

```php
/**
 * @link ../../../.docs/architecture/ingestion-pipeline.md
 *
 * @param  SourceTransactionDto  $dto  the normalized row to persist; may span
 *     multiple currencies, which are preserved verbatim
 * @return LedgerEntry
 *
 * @throws DuplicateTransactionException
 */
```

The following is a **violation** — it opens with a prose summary before any tag:

```php
/**
 * Persists a normalized transaction into the ledger, resolving the funding
 * chain along the way.          // ← banned: descriptive prose in a docblock
 *
 * @param SourceTransactionDto $dto
 */
```

Everything that paragraph was trying to say belongs in the linked `.docs` page.

## Enforcement

The mechanical rules are enforced by a Pest test that walks the in-scope files and
asserts on their comment tokens. It uses PHP's `token_get_all()` rather than a
comment-stripping regex, because the tokenizer natively distinguishes the three comment
species:

- `T_DOC_COMMENT` → `/** */` PHPDoc → M4, M5, M6 apply.
- `T_COMMENT` beginning `/*` → informative block → **M3 violation**.
- `T_COMMENT` beginning `//` (or `#`) → inline → M1, M2, M5 apply (unless allow-listed).

### Reference test

Copy this alongside the convention into any project. Tune `ALLOWED_DIRECTIVE` and the
scope roots to match the target repo. In beatrax it lands at
`tests/Contracts/CommentPolicyArchTest.php` and is activated once the docs sweep has
cleaned existing violations (see the enforcement phase in the roadmap).

```php
<?php

declare(strict_types=1);

/**
 * Enforces .docs/conventions/code-comments.md against backend production PHP.
 * token_get_all() separates PHPDoc (T_DOC_COMMENT) from informative and inline
 * comments (T_COMMENT), so each species is judged by the right rule.
 */

/** @return list<string> absolute paths to in-scope backend PHP files */
function commentPolicyBackendFiles(): array
{
    $roots = [base_path('Modules'), base_path('app')];
    $files = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }
            if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

/** A single-line comment that tooling reads — exempt from M1–M4. */
function commentPolicyIsDirective(string $text): bool
{
    return preg_match(
        '#^\s*(?://|/\*\*?)\s*@?(phpstan|psalm|phpcs|codeCoverage|var)\b#i',
        $text,
    ) === 1;
}

$bannedTokens = '/\b(TODO|FIXME|HACK|XXX|@todo)\b|\b[A-Z]{2,}-\d+\b|\bPhase\s+\d|\bD-\d|\b[A-Z]{2,4}-\d{2}\b/';

it('has no banned deferral or provenance tokens in comments (M5)', function () use ($bannedTokens) {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (preg_match($bannedTokens, $token[1]) === 1) {
                $hits[] = $path.':'.$token[2];
            }
        }
    }
    expect($hits)->toBe([], "Comments must carry no TODO/ticket/phase provenance. Offenders:\n  ".implode("\n  ", $hits));
});

it('has no informative /* */ block comments (M3)', function () {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || $token[0] !== T_COMMENT) {
                continue;
            }
            if (str_starts_with(ltrim($token[1]), '/*')) {
                $hits[] = $path.':'.$token[2];
            }
        }
    }
    expect($hits)->toBe([], "Use /** */ PHPDoc, never informative /* */ blocks. Offenders:\n  ".implode("\n  ", $hits));
});

it('has no lone single-line // comments and no // block over 4 lines (M1, M2)', function () {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
        $lines = [];
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && $token[0] === T_COMMENT && str_starts_with(ltrim($token[1]), '//')
                && ! commentPolicyIsDirective($token[1])) {
                $lines[] = $token[2];
            }
        }
        // Group adjacent line numbers into contiguous blocks.
        sort($lines);
        $block = [];
        $flush = function () use (&$block, &$hits, $path) {
            $n = count($block);
            if ($n >= 1 && $n < 2) {
                $hits[] = $path.':'.$block[0].' (lone single-line //)';
            } elseif ($n > 4) {
                $hits[] = $path.':'.$block[0]." ({$n}-line // block > 4)";
            }
            $block = [];
        };
        foreach ($lines as $line) {
            if ($block !== [] && $line !== end($block) + 1) {
                $flush();
            }
            $block[] = $line;
        }
        $flush();
    }
    expect($hits)->toBe([], "Inline // comments must be 2–4 line blocks. Offenders:\n  ".implode("\n  ", $hits));
});

it('has @-tag-only docblocks with no descriptive prose (M4)', function () {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }
            $seenTag = false;
            $hasContent = false;
            foreach (explode("\n", $token[1]) as $raw) {
                $line = trim(ltrim(trim($raw), '/*'));
                if ($line === '') {
                    continue;
                }
                $hasContent = true;
                if (str_starts_with($line, '@')) {
                    $seenTag = true;
                } elseif (! $seenTag) {
                    // Prose before the first @-tag — the banned shape.
                    $hits[] = $path.':'.$token[2];
                    break;
                }
            }
            if ($hasContent && ! $seenTag && ! in_array($path.':'.$token[2], $hits, true)) {
                $hits[] = $path.':'.$token[2].' (docblock with no @-tags)';
            }
        }
    }
    expect($hits)->toBe([], "Docblocks must be @-tag only. Offenders:\n  ".implode("\n  ", $hits));
});

it('has every @link .md target resolving to a real .docs file (M6)', function () {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }
            if (preg_match_all('/@link\s+(\S+\.md)/', $token[1], $m) === 0) {
                continue;
            }
            foreach ($m[1] as $target) {
                $resolved = realpath(dirname($path).'/'.$target);
                if ($resolved === false || ! is_file($resolved)) {
                    $hits[] = $path.':'.$token[2].' → '.$target;
                }
            }
        }
    }
    expect($hits)->toBe([], "@link .md targets must exist under .docs. Broken links:\n  ".implode("\n  ", $hits));
});
```

The `$bannedTokens` pattern and `commentPolicyIsDirective()` allow-list are the two knobs
that will need tuning against the real offender set during the sweep — treat the versions
above as the starting point, not the final word.

## Portability

To adopt this convention in another project:

1. Copy this file to `<project>/.docs/conventions/code-comments.md` and add a row to that
   tree's `00-index.md`.
2. Copy the reference test to the project's Pest `Contracts`/`Arch` suite; adjust the
   scope roots and allow-list.
3. Record the decision as an ADR in the target project.

The rules themselves carry no beatrax-specific assumptions — only the scope roots do.
