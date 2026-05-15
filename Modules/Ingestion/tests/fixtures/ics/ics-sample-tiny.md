# ICS PDF — tiny synthetic fixture

`ics-sample-tiny.pdf` is a deterministically-generated, ~849-byte
synthetic PDF used by `tests/Contracts/IdempotencyContractTest.php` to
exercise the wire-level re-import path through the real `pdftotext`
binary without depending on the human-provided raw export under
`local/ics/`.

NOT a real ICS export. The byte content is hand-crafted PDF 1.4 — one
Catalog, one Pages, one Page (Letter), one Type1 Helvetica font, one
content stream with six `Tj` text-show operators. No images, no
embedded fonts, no compression — `pdftotext -layout` yields the
embedded ASCII verbatim.

## Generator

```sh
php scripts/generate_tiny_ics_pdf.php
```

The script is committed alongside the fixture and is idempotent:
re-running overwrites the fixture byte-identically (no entropy in the
generator). Open the script for the full hand-crafted PDF byte
sequence; the only "magic" is the line offsets table the `xref`
section needs.

## Generator choice

A hand-crafted minimal PDF 1.4 byte stream is used in preference to
`cupsfilter`, which on macOS produces ~16–18 KB output even for a
six-line input (the Cairo / CoreGraphics pipeline embeds a Type1
subsetted font + multiple bounding-box headers that already exceed a
small budget). The committed generator at
`scripts/generate_tiny_ics_pdf.php` produces a fixture well under 1 KB
and requires no system-level tools, so any contributor can regenerate
the fixture from scratch with just `php`.

## Synthetic content

The PDF embeds the following six lines (the only `Tj`-shown text):

```
KAARTHOUDER ****-****-****-XXXX
Vorig openstaand saldo EUR 0,00
Totaal ontvangen betalingen EUR 0,00
Totaal nieuwe uitgaven EUR 1,00
Nieuw openstaand saldo EUR 1,00
12-04-2026 SYNTHETIC ICS TINY EUR 1,00
```

Load-bearing literals:

- `SYNTHETIC` — the contract test asserts the parsed transaction's
  merchant string contains this token.
- `KAARTHOUDER`, `****-****-****-XXXX` — canonical anonymisation
  placeholders. The repo-wide anonymised-fixture sweep test confirms
  they survive a round trip through `pdftotext`.
- `Vorig openstaand saldo`, `Totaal ontvangen betalingen`,
  `Totaal nieuwe uitgaven`, `Nieuw openstaand saldo` — empirical
  statement-summary anchor tokens (sourced from `ics-sample-1.md`'s
  statement-summary tokens section). The synthetic fixture uses the
  same token set as `ics-sample-1.txt` so the wire-level contract
  test stays internally consistent with the empirical fixture.

## Reproducibility

If `scripts/generate_tiny_ics_pdf.php` is unavailable on a future
contributor's machine (e.g. fresh clone with `composer install` not
yet run), regenerate via any PDF library that can render plain UTF-8
text — the only requirement is that `pdftotext -layout -enc UTF-8
-eol unix -nopgbrk <output>.pdf -` yields at least one line
containing the literal `SYNTHETIC` AND zero 12+ digit runs.
