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
pass over rows imported before the adapter fix. It is scoped to
`source_format = 'asn-csv'`: that is the only key `SourceAdapterRegistry` binds
to `AsnCsvAdapter`, so no other format can have produced these delimiters. The
CSV presets, ING's among them, route through `GenericCsvAdapter` under their own
format ids.

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

## Why a migration alone could not deliver it

The pass shipped run-once from a migration, and on the first sealed install it
reached that skip for **every** affected row. A migration runs when the schema
moves — at boot, before a lock screen has been cleared — which is never a
moment the app-lock key is held. It skipped all of them, wrote

```
StripAsnDescriptionDelimiters: skipped a sealed ledger this process cannot open.
```

to the log, and was recorded in `migrations` as Ran. There was no second chance:
the rows kept their delimiters permanently on the one kind of install the
feature was written for.

The migration is still there and still runs the pass, because an install with no
encryption is converted at deploy time and never has to wait. What it no longer
is, is the only delivery.

## Where the sealed install gets swept

`Modules\Ledger\Internal\Listeners\SweepAsnDelimitersOnUnlock` listens for
`Modules\Auth\Public\Events\AppLockUnlocked`. An unlock is by definition the
moment the key becomes reachable, so the pass is retried there until a user is
done. Two rules hold it in place:

- **It never throws.** The unlock has already happened, and a backfill that
  could not finish must not become an exception on the lock screen the reader
  just cleared. A failure is logged through `SafeExceptionContext`, the marker
  below stays unwritten, and the next unlock retries.
- **It never weakens the skip.** A sealed ledger this process cannot open is
  still left exactly as it is. The unlock changes when the pass runs, not what
  it is allowed to rewrite.

## Knowing there is nothing to do, cheaply

This now runs on every unlock, so the cost of having no work has to be near
zero — and it cannot be answered by decrypting descriptions to look for quotes.
Two reads answer it instead, and neither opens the ledger:

1. `ledger_backfill_state` — one indexed row per completed pass per user, keyed
   by `Modules\Ledger\Internal\Enums\BackfillPass`. It stores the **row set the
   pass answered for**: `swept_rows` and `swept_id_sum`.
2. One aggregate over that user's `asn-csv` rows, `COUNT(*)` and `SUM(id)`
   together. A user whose ledger still summarises to the recorded pair is done,
   and the sweep is not even resolved from the container — the codec, the
   encryption-state reader and the search index writer are never built. A user
   who never imported an ASN file summarises to zero and is recorded from this
   alone, so they pay for it once, ever.

The marker is written whenever a pass **ran to the end**, including over a
ledger it found nothing to change in — that pass has answered the question. It
is deliberately not written when the pass was skipped for want of a key, which
is what leaves the retry armed. It records the set read *before* the sweep, so a
row that arrived while the pass was running stays unanswered rather than being
recorded as swept by a pass that never saw it.

`ledger_backfill_state` is device-local and absent from `MergeRulesRegistry`, on
purpose. The sweep rewrites rows with a raw write that produces no op-log entry,
so a paired device still holds the delimiters in its own copy; a replicated
"done" would tell that device to skip the pass it still needs.

## Why it is not a highest-id watermark

The obvious cheap check is a high-water mark: remember the largest
`transactions.id` swept, and look for anything above it. **It does not work
here, and the reason is worth keeping.**

The sync daemon runs independently of the lock screen, so a peer that is still
locked — and has therefore never swept its own copy — can replicate pre-fix rows
into a device that already finished. Those rows do not get a local
autoincrement id. `Modules\Sync\Internal\Merge\OpLogEntryApplier` inserts them
under the **originating device's** id, deliberately:

> The op's own pk, not a fresh autoincrement. Without it every device invented
> its own id for the same logical row: children referenced parents that did not
> exist, and a replayed create duplicated instead of colliding with itself.

Ids are minted per device, with no offset between them, so an arriving row
routinely lands *below* everything this device has already swept. A watermark
steps straight over it and the row keeps its delimiters for good.

`COUNT(*)` with `SUM(id)` is order-free, which is what makes it immune: an
arrival moves both totals whichever id it takes. Count alone would miss a merge
that deleted one row and created another; the pair only agrees when the id set
sums to the same total with the same cardinality.

## Related

- [Ingestion architecture](architecture.md) — where the adapters live
- [Ledger architecture](../ledger/architecture.md) — the `transactions` table
