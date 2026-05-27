# Architecture

Architecture topics describe how the system fits together at the module, data, and
pipeline level — the cross-cutting shape that no single feature owns.

A topic in this subtree answers "how does X work across the codebase?" rather than
"how does Module Y behave?". Module-internal deep dives live under
[Features](../features/) instead.

## Topics

The architecture topics land in a follow-up pass. Expected coverage:

- Module map — what each bounded module owns, and the public-contract surface between
  them.
- Ingestion pipeline — how transactions flow from CSV / CAMT / MT940 / PDF / email
  sources into the canonical ledger.
- Chain resolution — how PayPal → funding-card → ICS bulk-iDEAL → ASN settlement
  chains are reconstructed.
- State machines — the trigger-enforced state columns used by chain resolution,
  recurring detection, drift alerts, and forecasting.
- Desktop shell — how the NativePHP-bundled app boots, where data lives, and what runs
  only inside the bundle vs. only under Herd.
