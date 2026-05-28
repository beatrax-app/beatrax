# History

Shipped milestones and the lessons that became invariants in the rest of the
documentation. Each milestone entry is a snapshot of what was delivered, the
patterns that proved out, and the operational learnings that paid forward into
arch tests, runbooks, and the module deep dives elsewhere in this tree.

This subtree is the only place the documentation reads chronologically. The
rest of the tree describes the system as it is today — when a historical
reason matters, that reason lives in the relevant Architecture Decision
Record's Context section, not in narrative prose.

## Topics

| File | What it covers |
| --- | --- |
| [milestones.md](milestones.md) | Shipped milestone roster — delivered scope, pinned stack, arch invariants enforced at each cut |
| [lessons-learned.md](lessons-learned.md) | Process learnings and engineering patterns proven at milestone close |

## How to add a milestone

When a milestone ships:

1. Append a new section to [milestones.md](milestones.md) with delivered scope,
   stack pin at cut, and arch invariants enforced at the cut.
2. Append a section to [lessons-learned.md](lessons-learned.md) capturing
   "what worked", "what was inefficient", and "patterns established". Phrase
   as observations the next milestone can act on, not retrospectives.
3. Promote any new architectural pattern that emerged into the appropriate
   [Architecture](../architecture/) topic file or, if novel enough to be a
   decision, an [Architecture Decision Record](../adr/).
