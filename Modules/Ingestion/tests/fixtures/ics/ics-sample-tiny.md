# ICS PDF — tiny synthetic fixture

`ics-sample-tiny.pdf` is a deterministically-generated, ~849-byte
synthetic PDF used by `tests/Contracts/IdempotencyContractTest.php`
(extended in plan 03-02) to exercise the wire-level re-import path
through the real `pdftotext` binary without depending on the human-
provided raw export under `local/ics/`.

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

## Deviation note — generator choice

The plan's primary path was `cupsfilter` (ships with macOS), but
`cupsfilter` on this host produces ~16–18 KB output even for a
six-line input — the Cairo / CoreGraphics pipeline embeds a Type1
subsetted font + a /MediaBox /CropBox /BleedBox /TrimBox /ArtBox
header that alone exceeds the 10 KB budget. The plan's documented
fallback ("hand-crafted ~400-byte PDF byte string") is the chosen
path. The output is well under the 10 KB acceptance gate.

The fallback generator lives at `scripts/generate_tiny_ics_pdf.php`.
Any future contributor can regenerate the fixture from scratch by
running the script — no system-level tools required.

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

- `SYNTHETIC` — the Wave 2 contract test asserts the parsed
  transaction's merchant string contains this token.
- `KAARTHOUDER`, `****-****-****-XXXX` — canonical anonymisation
  placeholders. The Task 7 sweep test confirms they survive a round
  trip through `pdftotext`.
- `Vorig openstaand saldo`, `Totaal ontvangen betalingen`,
  `Totaal nieuwe uitgaven`, `Nieuw openstaand saldo` — empirical
  statement-summary anchor tokens (sourced from
  `ics-sample-1.md`'s "Statement summary tokens (D-51)" section).
  The CONTEXT.md aspirational tokens (`Periode`, `Beginsaldo`,
  `Eindsaldo`, `Totaal nieuw saldo`, `Totaal betaald`) do NOT appear
  in real Mijn ICS consumer-portal exports — this synthetic fixture
  uses the empirical token set so the Wave 2 wire-level contract test
  stays internally consistent with `ics-sample-1.txt`.

## Reproducibility

If `scripts/generate_tiny_ics_pdf.php` is unavailable on a future
contributor's machine (e.g. fresh clone with `composer install` not
yet run), regenerate via any PDF library that can render plain UTF-8
text — the only requirement is that `pdftotext -layout -enc UTF-8
-eol unix -nopgbrk <output>.pdf -` yields at least one line
containing the literal `SYNTHETIC` AND zero 12+ digit runs.
