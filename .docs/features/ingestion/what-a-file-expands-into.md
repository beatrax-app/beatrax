# What a file expands into

The import wizard caps the **upload**: 10 MB for a statement, 1 MB for an
`.mbox`, 20 KB for an `.eml`. That is a cap on the file. It is not a cap on
what the file costs to hold, and each reader here costs a different multiple
of it.

The whole backend runs on the reader's phone inside a 128 MB ceiling, where an
exhausted heap is `E_ERROR`: no exception, no log line, no wizard left to
render the failure on. Every figure below was measured with
`memory_limit=128M` against a booted application (38.5 MB of that is the boot
itself).

## What was measured

| File | Inside the upload cap | What it cost | Outcome |
| --- | --- | --- | --- |
| CAMT.053 XML, 62,497 `<Ntry>` | 9.5 MB | > 128 MB | fatal, inside genkgo's date decoder, before the first row |
| CAMT.053 XML, 3,676 `<Ntry>` | 9.5 MB | 67.6 MB | parsed |
| N26 CSV, one 2,000,005-column row | 3.8 MB | > 128 MB | fatal, inside `fgetcsv` |
| N26 CSV, 300,005 columns | 1.1 MB | 94.5 MB | parsed |
| ICS PDF, one stream inflating to 50 MB | 49 KB | > 128 MB | fatal, inside the vendor's `str_replace` |
| ICS PDF, 200,000 text runs | 12.7 KB | 45 minutes | ran |
| ICS PDF, one run at x = 100,000,000,000 | 602 B | 20 GB asked for | fatal, in `str_repeat` |

The last three are the phone's path specifically: `PdfTextExtractor` prefers
poppler and falls back to the pure-PHP `PdfTextLayoutReader`, and neither iOS
nor Android permits running a second binary. `pdftotext` reads the 12.7 KB
file in 0.43 s.

## Why the file's size is the wrong axis

Two CAMT files of 9.5 MB differ by a factor of 17 in what they cost, because
the cost is per booked entry — genkgo builds the whole statement before the
adapter yields its first row — and a crafted entry is 152 bytes where a real
one is about 2 KB. A cap on bytes tight enough to stop the first refuses a
year of the second.

The same holds elsewhere. `fgetcsv` holds one whole line as an array of cells,
so what decides the CSV read's peak is the longest **line**, not the row count
and not the file size. A PDF's layout cost is the square of the text runs on a
page, and a run can be spelled in 26 bytes of a stream that inflates a
thousand to one.

## The ceilings, and where they are enforced

`HeaderSniffer` is where the first three live, because it is the module's one
validation seam: the wizard calls it, and every adapter calls it again on its
own first line, so a caller that skipped the wizard is refused just the same.
All of them raise `ReadCeilingExceededException`, which is deliberately **not**
a `NamesAFormatMismatch` — the file is the format it claims to be — so `Import`
reports `FileStoppedShort`, whose copy already says a file can be too large for
this device and to try a shorter date range.

- **`SourceFileCeilings::MAX_CSV_LINE_BYTES` (64 KB)** — the longest line any
  CSV may carry, scanned with `stream_get_line` so measuring costs one buffer.
  Checked for every CSV arm: the header-name presets, the positional presets
  and PayPal. A shipped bank row runs to a few hundred bytes.
- **`SourceFileCeilings::MAX_CAMT_ENTRIES` (20,000)** — counted by scanning for
  `<Ntry` followed by a space, `>` or `/`. Anchored on the whole tag because
  `<Ntry` also opens `<NtryDtls>` and `<NtryRef>`, which every real entry
  carries; counting those would refuse a statement for its own detail blocks. A
  prefixed spelling is not covered, because genkgo refuses one outright
  ("cannot find message format with xmlns"). A busy account books about 2,700
  entries a year; the shipped ASN fixture books 229.
- **`PdfTextLayoutReader::MAX_CONTENT_BYTES` (128 KB)** — the decoded
  content-stream bytes one document may lay out, summed across its pages
  without concatenating them (`PdfContentSize`). The same number is handed to
  smalot's own decoder as `setDecodeMemoryLimit`, because a stream that
  inflates past the ceiling has already spent the memory by the time the sum
  can be read; a stream the decoder cut therefore lands exactly on the ceiling,
  which is why the check is `>=` and not `>`. Laying out this much costs 1.7 s,
  twice it costs 7 s; the shipped statement spends 2,643 bytes.

  Held against real statements rather than against the fixtures: the seven PDFs
  in this install's own `storage/app/private/imports`, measured through
  `PdfContentSize::ofPage()`, all sit under it, and the worst single page
  spends 12,966 bytes — 9.9% of the ceiling, about ten times' headroom. That is
  the number that makes it defensible rather than plausible, because a ceiling
  that silently refuses a legitimate statement is harder to diagnose than the
  crash it prevents.
- **`PdfTextLayoutReader::MAX_STATEMENT_PAGES` (100)** — for the document whose
  pages carry no content at all, which the byte ceiling above cannot see.
  Qualified rather than left as `MAX_PAGES`, because the tree already holds a
  `MAX_PAGES = 100` meaning pages of a bank API's pagination; `DiscoveryScanJob`
  answers the same question the same way with `DISCOVERY_MAX_PAGES`.
- **`PdfTextLayoutReader::MAX_COLUMNS` (4,096)** — a column index is the run's
  own x coordinate divided by a nominal glyph advance, and it reaches
  `str_repeat`. A page is 612 points wide and every column on a real statement
  lands under 60.

`setRetainImageContent(false)` goes with them: nothing in this reader looks at
an image, and holding every one of them was the largest allocation it made on a
statement that had any.

## What is still unbounded

A PDF assembled from thousands of separate compressed content streams can
still push the vendor's *total* decoded bytes past the heap during
`parseFile()`, because `setDecodeMemoryLimit` bounds each stream and smalot
offers no cumulative budget. The ceiling above is read after the parse
returns, so it cannot see that case. What bounds it today is the 10 MB upload
cap and nothing else.

Two mechanisms were measured and found to carry no consequence, and are
recorded here so they are not re-investigated:

- **XML entity expansion** in CAMT. A billion-laughs `camt.053` (1,941 bytes,
  `&a9;` expanding to 10^12) is refused by libxml itself — `Empty document` —
  because neither `LIBXML_NOENT` nor `LIBXML_PARSEHUGE` is set. The external
  entity loader the adapter installs closes XXE; entity expansion was already
  closed.
- **The MT940 SWIFT-envelope branch**, which `preg_split`s block 4 into lines
  before its `MAX_LINE_COUNT` cap is consulted. Block 4 is captured by a lazy
  `[\s\S]+?`, which exhausts `pcre.backtrack_limit` at about 1 MB and returns
  false; the branch then refuses the file. A 9 MB envelope never reaches the
  split.
