# ASN description delimiters

ASN wraps the description field of its CSV export in apostrophes. They are a
**delimiter**, the way a quote character delimits a CSV cell — not punctuation
the bank intended the reader to see. Carried into the ledger they showed up on
every screen that prints a description, and the full-text index indexed them:

```
'Rentevergoeding tweede kwartaal'
```

`AsnCsvAdapter` now strips them at parse time. The rule lives once, in
`Modules\Ingestion\Public\Asn\AsnDescriptionDelimiters`, because two things
apply it and they must not be able to disagree.

## The rule

`unwrap()` removes **one matching leading-and-trailing pair**, then trims.
Everything else is left exactly as it arrived:

| Stored value | Result | Why |
|---|---|---|
| `'FEBRUARI 2026'` | `FEBRUARI 2026` | a matching pair |
| `Bakkerij 't Stoepje` | unchanged | apostrophe is punctuation, no pair |
| `'incomplete` | unchanged | no closing delimiter |
| `''doubled''` | unchanged | see below |

`raw_payload` keeps the untouched row in every case. It is the audit trail, so
nothing in this feature rewrites it.

## Why the two-pair case is left alone

`unwrapStored()` refuses a value that still has a matching pair after one
unwrap. Stripping a second pair would disagree with the adapter, which strips
exactly one — and a backfill that removes more each time it runs is not
idempotent. Leaving it whole keeps the two implementations answerable to the
same rule.

## Joined descriptions

The adapter joins several source fields into one description with ` / `. A
backfill therefore cannot simply unwrap the stored string: each part carries
its own delimiters. `splitJoined()` splits **only where a separator touches a
delimiter**, which makes the split lossless — imploding an unsplit value
reproduces it byte for byte. A separator inside one bank narrative,
`NL-1234 / FEBRUARI 2026`, is never mistaken for the join between two fields.

If every part unwraps away to nothing, the row should hold no description at
all, and `unwrapStored()` returns `null` to say so.

## The backfill

`Modules\Ledger\Internal\Services\StripAsnDescriptionDelimiters` is the forward
pass over rows imported before the adapter fix, run once from a migration. It
is scoped to `source_format = 'asn-csv'`: that is the only key
`SourceAdapterRegistry` binds to `AsnCsvAdapter`, so no other format can have
produced these delimiters. The CSV presets, ING's among them, route through
`GenericCsvAdapter` under their own format ids.

Three things make it safe to run on a live install:

- **Encryption.** `transactions.description` is a registered sensitive column.
  The sweep decrypts, rewrites, and re-encrypts per user. A user whose ledger
  is sealed and whose key this process does not hold is **skipped with a
  warning**, never rewritten — writing the unwrap of ciphertext would destroy
  the column. An empty plaintext out of a non-empty stored value is that same
  case and is skipped too.
- **Search.** The FTS5 document is stored, not derived, so a ledger row and its
  search document are written inside one transaction per chunk. A crash
  mid-sweep cannot leave a document still carrying quotes the ledger no longer
  has.
- **Re-runs.** A row already unwrapped produces no change, so the pass is
  idempotent and safe on an install with nothing to do.

## Related

- [Ingestion architecture](architecture.md) — where the adapters live
- [Ledger architecture](../ledger/architecture.md) — the `transactions` table
