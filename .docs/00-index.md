# beatrax documentation

This tree is the published, version-controlled documentation for beatrax — a local-only
personal-finance dashboard that resolves the routing chains between banking, ICS Cards,
PayPal, and Google Play into one canonical view.

The structure mirrors happklaar's `.docs/` layout: a small set of top-level subtrees,
each with its own `00-index.md` for navigation. Decisions live under `adr/`. Module-level
deep dives live under `features/`. System-level shape lives under `architecture/`.
Operational, build, and legal concerns each have their own subtree.

Documentation here describes the system as it currently is. Architecture decision records
under `adr/` carry their own history through Status / Context / Decision / Consequences,
but the rest of the tree reads as standalone reference — no "we used to do X" prose.

## Subtrees

| Subtree | What it covers |
|---|---|
| [Architecture Decision Records](adr/) | Why the system is shaped the way it is |
| [Architecture](architecture/) | Module boundaries, pipelines, data model |
| [Conventions](conventions/) | Day-to-day coding rules (e.g. code comments) |
| [Features](features/) | Per-module deep dives |
| [CI/CD](cicd/) | Quality gate, release pipeline, branch protection |
| [Local Development](local_development/) | Setup, database, troubleshooting, dev mode |
| [Runbooks](runbooks/) | Operational procedures |
| [Research](research/) | Known hazards, stack rationale, packaging hazards |
| [History](history/) | Shipped milestones and the lessons that became invariants |
| [Legal](legal/) | License rationale, data retention |

## Conventions

- **Present tense.** Every file describes the system as it is today. If a behaviour is
  going away, that goes in an ADR's Consequences section, not in the narrative docs.
- **Cross-links are relative.** Internal references use paths like `../runbooks/verify-release.md`
  so the tree renders correctly both on GitHub and in any offline mirror.
- **One topic per file.** Files are short and focused. A topic that grows past a few
  screens of prose gets split rather than padded.
- **Phase-numbered context is allowed.** Where a decision graduated from a phase of work,
  the ADR may cite the phase number (`Phase 17`, `D-23`) as historical provenance. The
  rest of the tree avoids those references — they are noise to a first-time reader.
