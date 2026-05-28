# Research notes

Distilled hazard catalogues and stack-decision rationale that informed the
shape of the system. These notes complement the [Architecture Decision
Records](../adr/), the [Architecture](../architecture/) topics, and the
[Feature](../features/) deep dives — those documents say *what is true today*;
the notes here capture the *forcing functions* behind those truths.

The catalogues here are reference material a contributor reaches for when
asking "why did we pick this and not the obvious alternative?" or "what
breaks if I touch this pattern?" — not narrative history. The answers are
short, present-tense, and link to the canonical document where the rule lives.

## Topics

| File | What it covers |
| --- | --- |
| [known-hazards.md](known-hazards.md) | The pitfall catalogue the codebase actively guards against — floats in money math, unstable transaction identity, PayPal CSV event-log shape, ICS bulk-settlement reconciliation, IMAP / WAL / multi-user retrofit traps |
| [stack-rationale.md](stack-rationale.md) | Why each load-bearing dependency was picked over its obvious alternative, and the three v1.0 → desktop-bundle stack flips that constrain shipped builds |
| [packaging-hazards.md](packaging-hazards.md) | The desktop-bundle-specific pitfalls — `AppPaths`, first-run migration, Horizon-out-of-bundle, hardened-runtime entitlements, signing-secret exposure |
